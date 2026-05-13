<?php

namespace App\Modules\People\Payroll\Services;

use App\Modules\People\Payroll\Contracts\Intake\PayrollContributionOutcome;
use App\Modules\People\Payroll\Contracts\Intake\PayrollContributionState;
use App\Modules\People\Payroll\Models\PayrollPendingContribution;
use App\Modules\People\Payroll\Models\PayrollRun;
use DateTimeInterface;

/**
 * Read API for producer modules. Producers ask "what did Payroll do with this
 * source?" without querying payroll_inputs directly.
 *
 * The returned state is derived from the current PayrollRun status, so a
 * materialized contribution whose run later moved to `calculated`/`closed`
 * reports the live state, not the state at materialization time.
 */
class PayrollContributionStatus
{
    /**
     * Look up the status of a specific (source_type, source_id, pay_item_code, period_anchor) tuple.
     * Pay item code and period anchor are optional: when omitted the most recent
     * row for the (source_type, source_id) pair is returned, which is sufficient
     * when the producer's source is naturally 1:1 with a contribution.
     */
    public function for(
        string $sourceType,
        int $sourceId,
        ?string $payItemCode = null,
        ?DateTimeInterface $periodAnchor = null,
    ): PayrollContributionOutcome {
        $query = PayrollPendingContribution::query()
            ->where('source_type', $sourceType)
            ->where('source_id', $sourceId);

        if ($payItemCode !== null) {
            $query->where('pay_item_code', $payItemCode);
        }
        if ($periodAnchor !== null) {
            $query->whereDate('period_anchor', $periodAnchor->format('Y-m-d'));
        }

        $pending = $query->orderByDesc('id')->first();

        if ($pending === null) {
            return new PayrollContributionOutcome(state: PayrollContributionState::ABSENT);
        }

        return $this->resolveOutcome($pending);
    }

    /**
     * Return all contributions for one source identity, useful when an aggregate
     * source (e.g. an attendance period) fans out into many pay items.
     *
     * @return list<PayrollContributionOutcome>
     */
    public function allFor(string $sourceType, int $sourceId): array
    {
        return PayrollPendingContribution::query()
            ->where('source_type', $sourceType)
            ->where('source_id', $sourceId)
            ->orderBy('id')
            ->get()
            ->map(fn (PayrollPendingContribution $row): PayrollContributionOutcome => $this->resolveOutcome($row))
            ->all();
    }

    private function resolveOutcome(PayrollPendingContribution $pending): PayrollContributionOutcome
    {
        $run = null;
        $state = $pending->state;

        if ($pending->payroll_input_id !== null) {
            $pending->loadMissing('payrollInput.run');
            $run = $pending->payrollInput?->run;

            if ($run !== null) {
                $state = $this->stateForRun($run, $pending->state);
            }
        }

        return new PayrollContributionOutcome(
            state: $state,
            payrollInputId: $pending->payroll_input_id,
            payrollRunId: $run?->id,
            payrollRunStatus: $run?->status,
            payrollPendingContributionId: (int) $pending->id,
            reason: $pending->reason,
        );
    }

    private function stateForRun(PayrollRun $run, string $pendingState): string
    {
        if ($pendingState === PayrollContributionState::REVERSED) {
            return PayrollContributionState::REVERSED;
        }

        return match ($run->status) {
            PayrollRun::STATUS_DRAFT => PayrollContributionState::QUEUED_IN_RUN,
            PayrollRun::STATUS_CALCULATED, PayrollRun::STATUS_REVIEWED, PayrollRun::STATUS_APPROVED
                => PayrollContributionState::CALCULATED,
            PayrollRun::STATUS_CLOSED => PayrollContributionState::CLOSED,
            PayrollRun::STATUS_VOIDED => PayrollContributionState::VOIDED,
            default => $pendingState,
        };
    }
}
