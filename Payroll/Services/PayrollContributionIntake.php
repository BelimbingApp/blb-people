<?php

namespace App\Modules\People\Payroll\Services;

use App\Modules\People\Payroll\Contracts\Intake\PayrollContributionOutcome;
use App\Modules\People\Payroll\Contracts\Intake\PayrollContributionPayload;
use App\Modules\People\Payroll\Contracts\Intake\PayrollContributionState;
use App\Modules\People\Payroll\Models\PayrollInput;
use App\Modules\People\Payroll\Models\PayrollPendingContribution;
use App\Modules\People\Payroll\Models\PayrollRun;
use App\Modules\People\Payroll\Models\PayrollRunParticipant;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Single Payroll-owned write path for producer-side contributions.
 *
 * Producers (Claim, Leave, Attendance) call ingest() with a typed payload.
 * They do NOT import PayrollInput, PayrollRun, or PayrollRunParticipant.
 *
 * Guarantees:
 *  - exactly one PayrollPendingContribution row per composite source tuple
 *    (DB unique index, not application check);
 *  - automatic PayrollRunParticipant creation;
 *  - locked-run targeting returns rejected_locked instead of mutating;
 *  - idempotent: re-ingesting the same payload returns the existing row.
 */
class PayrollContributionIntake
{
    /** Writer window: runs in these statuses accept new inputs from intake. */
    private const WRITABLE_RUN_STATUSES = [
        PayrollRun::STATUS_DRAFT,
        PayrollRun::STATUS_CALCULATED,
    ];

    /**
     * Ingest a contribution. Idempotent on (source_type, source_id, pay_item_code, period_anchor).
     */
    public function ingest(PayrollContributionPayload $payload): PayrollContributionOutcome
    {
        $pending = $this->upsertPending($payload);

        if ($pending->isMaterialized() || $pending->state === PayrollContributionState::REVERSED) {
            return $this->outcomeFor($pending);
        }

        return DB::transaction(function () use ($pending, $payload): PayrollContributionOutcome {
            $pending->refresh();

            // Re-fired between upsert and materialization; honour current state.
            if ($pending->isMaterialized() || $pending->state === PayrollContributionState::REVERSED) {
                return $this->outcomeFor($pending);
            }

            $run = $this->findRunFor($payload);
            if ($run === null) {
                $pending->state = PayrollContributionState::PENDING;
                $pending->save();

                return $this->outcomeFor($pending);
            }

            if (! in_array($run->status, self::WRITABLE_RUN_STATUSES, true)) {
                $pending->state = $run->isClosed()
                    ? PayrollContributionState::REJECTED_LOCKED
                    : PayrollContributionState::PENDING;
                $pending->reason = sprintf('run %d is %s', $run->id, $run->status);
                $pending->save();

                return $this->outcomeFor($pending, $run);
            }

            $input = $this->materialize($pending, $payload, $run);
            $pending->payroll_input_id = (int) $input->id;
            $pending->state = PayrollContributionState::QUEUED_IN_RUN;
            $pending->materialized_at = now();
            $pending->save();

            return $this->outcomeFor($pending, $run, $input);
        });
    }

    /**
     * Reverse a previously ingested contribution by composite key.
     *
     * - pending row not yet materialized → marked reversed, no PayrollInput touched.
     * - materialized into a draft run → input deleted, pending marked reversed.
     * - materialized into a calculated run → input deleted, pending marked reversed.
     *   (PayrollInput::saving guard blocks closed/voided.)
     * - materialized into a closed/voided run → not deleted; pending marked reversed
     *   with reason; caller is expected to inspect outcome and post a compensating
     *   contribution if business policy requires it.
     */
    public function reverse(
        string $sourceType,
        int $sourceId,
        string $payItemCode,
        \DateTimeInterface $periodAnchor,
        ?string $reason = null,
    ): PayrollContributionOutcome {
        $anchor = $periodAnchor instanceof \DateTimeImmutable
            ? $periodAnchor
            : \DateTimeImmutable::createFromInterface($periodAnchor);

        return DB::transaction(function () use ($sourceType, $sourceId, $payItemCode, $anchor, $reason): PayrollContributionOutcome {
            $pending = PayrollPendingContribution::query()
                ->where('source_type', $sourceType)
                ->where('source_id', $sourceId)
                ->where('pay_item_code', $payItemCode)
                ->whereDate('period_anchor', $anchor->format('Y-m-d'))
                ->lockForUpdate()
                ->first();

            if ($pending === null) {
                return new PayrollContributionOutcome(
                    state: PayrollContributionState::ABSENT,
                    reason: $reason,
                );
            }

            if ($pending->state === PayrollContributionState::REVERSED) {
                return $this->outcomeFor($pending);
            }

            $run = null;
            $input = $pending->payroll_input_id === null
                ? null
                : PayrollInput::query()->with('run')->find($pending->payroll_input_id);

            if ($input !== null) {
                $run = $input->run;
                if ($run !== null && ! $run->isClosed()) {
                    $input->delete();
                    $pending->payroll_input_id = null;
                }
            }

            $pending->state = PayrollContributionState::REVERSED;
            $pending->reason = $reason ?? $pending->reason;
            $pending->reversed_at = now();
            $pending->save();

            return $this->outcomeFor($pending, $run);
        });
    }

    /**
     * Idempotent upsert of the pending row. Uses an atomic insert path so two
     * concurrent producers cannot both create a row for the same composite key.
     */
    private function upsertPending(PayrollContributionPayload $payload): PayrollPendingContribution
    {
        $anchor = $payload->periodAnchor->format('Y-m-d');
        $tupleQuery = fn () => PayrollPendingContribution::query()
            ->where('source_type', $payload->sourceType)
            ->where('source_id', $payload->sourceId)
            ->where('pay_item_code', $payload->payItemCode)
            ->whereDate('period_anchor', $anchor);

        $existing = $tupleQuery()->first();
        if ($existing !== null) {
            return $existing;
        }

        $values = [
            'source_type' => $payload->sourceType,
            'source_id' => $payload->sourceId,
            'pay_item_code' => $payload->payItemCode,
            'period_anchor' => $anchor,
        ] + [
            'company_id' => $payload->companyId,
            'employee_id' => $payload->employeeId,
            'occurred_on' => $payload->occurredOn->format('Y-m-d'),
            'input_type' => $payload->inputType,
            'currency' => $payload->currency,
            'amount' => $payload->amount,
            'quantity' => $payload->quantity,
            'rate' => $payload->rate,
            'label' => $payload->label,
            'accounting_snapshot' => $payload->accountingSnapshot,
            'state' => PayrollContributionState::PENDING,
            'metadata' => $payload->metadata,
        ];

        try {
            return PayrollPendingContribution::query()->create($values);
        } catch (QueryException $e) {
            if ($this->isUniqueViolation($e)) {
                $row = $tupleQuery()->first();
                if ($row !== null) {
                    return $row;
                }
            }

            throw $e;
        }
    }

    private function materialize(
        PayrollPendingContribution $pending,
        PayrollContributionPayload $payload,
        PayrollRun $run,
    ): PayrollInput {
        $participant = $this->ensureParticipant($run, $payload->employeeId);

        return PayrollInput::query()->create([
            'payroll_run_id' => $run->id,
            'payroll_run_participant_id' => $participant->id,
            'employee_id' => $payload->employeeId,
            'source_type' => $payload->sourceType,
            'source_id' => $payload->sourceId,
            'pay_item_code' => $payload->payItemCode,
            'label' => $payload->label,
            'input_type' => $payload->inputType,
            'quantity' => $payload->quantity ?? 1,
            'rate' => $payload->rate,
            'amount' => $payload->amount ?? 0,
            'currency' => $payload->currency,
            'occurred_on' => $payload->occurredOn->format('Y-m-d'),
            'metadata' => array_merge($payload->metadata, [
                'payroll_pending_contribution_id' => $pending->id,
                'accounting_snapshot' => $payload->accountingSnapshot,
            ]),
        ]);
    }

    private function findRunFor(PayrollContributionPayload $payload): ?PayrollRun
    {
        $anchor = $payload->periodAnchor->format('Y-m-d');
        $base = PayrollRun::query()
            ->where('company_id', $payload->companyId)
            ->whereHas('period', function ($query) use ($anchor): void {
                $query->where('starts_on', '<=', $anchor)
                    ->where('ends_on', '>=', $anchor);
            })
            ->with('period');

        // Prefer a writable run; fall back to any matching run so the caller can
        // detect locked/reviewed status and respond per policy.
        $writable = (clone $base)
            ->whereIn('status', self::WRITABLE_RUN_STATUSES)
            ->orderBy('id')
            ->first();

        return $writable ?? $base->orderBy('id')->first();
    }

    private function ensureParticipant(PayrollRun $run, int $employeeId): PayrollRunParticipant
    {
        $participant = PayrollRunParticipant::query()
            ->where('payroll_run_id', $run->id)
            ->where('employee_id', $employeeId)
            ->first();

        if ($participant !== null) {
            return $participant;
        }

        try {
            return PayrollRunParticipant::query()->create([
                'payroll_run_id' => $run->id,
                'company_id' => $run->company_id,
                'employee_id' => $employeeId,
                'status' => 'included',
                'currency' => $run->currency,
            ]);
        } catch (QueryException $e) {
            if ($this->isUniqueViolation($e)) {
                $existing = PayrollRunParticipant::query()
                    ->where('payroll_run_id', $run->id)
                    ->where('employee_id', $employeeId)
                    ->first();
                if ($existing !== null) {
                    return $existing;
                }
            }

            throw $e;
        }
    }

    private function outcomeFor(
        PayrollPendingContribution $pending,
        ?PayrollRun $run = null,
        ?PayrollInput $input = null,
    ): PayrollContributionOutcome {
        $resolvedRun = $run ?? $input?->run;

        return new PayrollContributionOutcome(
            state: $pending->state,
            payrollInputId: $pending->payroll_input_id ?? $input?->id,
            payrollRunId: $resolvedRun?->id,
            payrollRunStatus: $resolvedRun?->status,
            payrollPendingContributionId: (int) $pending->id,
            reason: $pending->reason,
        );
    }

    private function isUniqueViolation(Throwable $e): bool
    {
        return $e instanceof QueryException && (string) $e->getCode() === '23000';
    }
}
