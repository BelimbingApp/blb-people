<?php

namespace App\Modules\People\Claim\Services;

use App\Modules\People\Claim\Models\ClaimLine;
use App\Modules\People\Claim\Models\ClaimRequest;
use App\Modules\People\Claim\Models\ClaimRequestAuditEvent;
use App\Modules\People\Payroll\Models\PayrollInput;
use App\Modules\People\Payroll\Models\PayrollRun;
use App\Modules\People\Payroll\Models\PayrollRunParticipant;

class ClaimPayrollHandoffService
{
    private const OPEN_RUN_STATUSES = [
        PayrollRun::STATUS_DRAFT,
        PayrollRun::STATUS_CALCULATED,
    ];

    /**
     * @return array{eligible: int, queued: int, pending: int, skipped: int}
     */
    public function queueApprovedRequest(ClaimRequest $request, ?int $actorUserId = null): array
    {
        $request->loadMissing(['lines.type']);

        $eligible = 0;
        $queued = 0;
        $pending = 0;
        $skipped = 0;

        foreach ($request->lines as $line) {
            if (! $this->isPayrollEligible($line)) {
                $skipped++;

                continue;
            }

            $eligible++;

            $input = $this->queueLine($request, $line);
            if ($input === null) {
                $pending++;

                continue;
            }

            $queued++;
        }

        $summary = compact('eligible', 'queued', 'pending', 'skipped');
        $metadata = is_array($request->metadata) ? $request->metadata : [];
        $metadata['payroll_handoff'] = $summary;
        $request->metadata = $metadata;

        if ($eligible > 0 && $pending === 0 && $queued === $eligible) {
            $fromStatus = $request->status;
            $request->status = ClaimRequest::STATUS_QUEUED_FOR_PAYROLL;
            $request->queued_for_payroll_at = now();

            ClaimRequestAuditEvent::query()->create([
                'claim_request_id' => $request->getKey(),
                'from_status' => $fromStatus,
                'to_status' => ClaimRequest::STATUS_QUEUED_FOR_PAYROLL,
                'actor_user_id' => $actorUserId,
                'reason' => 'queued_for_payroll',
                'occurred_at' => now(),
                'metadata' => $summary,
            ]);
        }

        $request->save();

        return $summary;
    }

    private function queueLine(ClaimRequest $request, ClaimLine $line): ?PayrollInput
    {
        $existing = PayrollInput::query()
            ->where('source_type', 'claim_line')
            ->where('source_id', $line->getKey())
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        $run = $this->findOpenRunFor($request, $line);
        if ($run === null) {
            return null;
        }

        $participant = $this->ensureParticipant($run, (int) $request->employee_id);

        return PayrollInput::query()->create([
            'payroll_run_id' => $run->getKey(),
            'payroll_run_participant_id' => $participant->getKey(),
            'employee_id' => $request->employee_id,
            'source_type' => 'claim_line',
            'source_id' => $line->getKey(),
            'pay_item_code' => $line->payroll_pay_item_code,
            'label' => $line->type?->name ?? 'Claim reimbursement',
            'input_type' => PayrollInput::TYPE_REIMBURSEMENT,
            'quantity' => 1,
            'rate' => null,
            'amount' => $line->approved_amount,
            'currency' => $line->currency,
            'occurred_on' => $line->incurred_on,
            'metadata' => [
                'claim_request_id' => $request->getKey(),
                'claim_reference_number' => $request->reference_number,
                'claim_type_id' => $line->claim_type_id,
                'claim_type_code' => $line->type?->code,
                'debit_account_code' => $line->debit_account_code,
                'credit_account_code' => $line->credit_account_code,
            ],
        ]);
    }

    private function isPayrollEligible(ClaimLine $line): bool
    {
        return (bool) $line->type?->payroll_eligible
            && $line->payroll_pay_item_code !== null
            && (float) $line->approved_amount > 0;
    }

    private function findOpenRunFor(ClaimRequest $request, ClaimLine $line): ?PayrollRun
    {
        return PayrollRun::query()
            ->where('company_id', $request->company_id)
            ->whereIn('status', self::OPEN_RUN_STATUSES)
            ->whereHas('period', function ($query) use ($line): void {
                $query->where('starts_on', '<=', $line->incurred_on)
                    ->where('ends_on', '>=', $line->incurred_on);
            })
            ->orderBy('id')
            ->first();
    }

    private function ensureParticipant(PayrollRun $run, int $employeeId): PayrollRunParticipant
    {
        $participant = PayrollRunParticipant::query()
            ->where('payroll_run_id', $run->getKey())
            ->where('employee_id', $employeeId)
            ->first();

        if ($participant !== null) {
            return $participant;
        }

        return PayrollRunParticipant::query()->create([
            'payroll_run_id' => $run->getKey(),
            'company_id' => $run->company_id,
            'employee_id' => $employeeId,
            'status' => 'included',
            'currency' => $run->currency,
        ]);
    }
}
