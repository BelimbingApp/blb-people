<?php

namespace App\Modules\People\Leave\Services;

use App\Modules\People\Leave\Models\LeaveBalanceLedgerEntry;
use App\Modules\People\Leave\Models\LeaveRequest;
use App\Modules\People\Payroll\Models\PayrollInput;
use App\Modules\People\Payroll\Models\PayrollRun;
use App\Modules\People\Payroll\Models\PayrollRunParticipant;

/**
 * Bridges Leave Core → Payroll Core via the neutral PayrollInput surface.
 *
 * Strategy: find an open draft PayrollRun (status in draft/calculated) whose
 * period covers the leave start date. If found, write a PayrollInput row
 * attached to the run and the matching participant; otherwise, record the
 * pending handoff on the ledger entry metadata so a future
 * {@see LeavePayrollPendingDrainer} can attach it when a run is opened.
 *
 * Each unpaid-leave or encashment row carries the source leave request ID;
 * (source_type, source_id) is queried to deduplicate so a re-apply cannot
 * double-count.
 */
class LeavePayrollHandoffService
{
    private const OPEN_RUN_STATUSES = [
        PayrollRun::STATUS_DRAFT,
        PayrollRun::STATUS_CALCULATED,
    ];

    public function onLeaveApplied(LeaveRequest $request, LeaveBalanceLedgerEntry $entry): ?PayrollInput
    {
        $leaveType = $request->leaveType;
        if ($leaveType === null || ! $leaveType->interacts_with_payroll) {
            return null;
        }

        $payItemCode = $leaveType->payroll_pay_item_code;
        if ($payItemCode === null) {
            return null;
        }

        $existing = PayrollInput::query()
            ->where('source_type', 'leave_request')
            ->where('source_id', $request->getKey())
            ->first();
        if ($existing !== null) {
            return $existing;
        }

        $run = $this->findOpenRunFor($request);
        if ($run === null) {
            return null;
        }

        $participant = $this->ensureParticipant($run, (int) $request->employee_id);

        return PayrollInput::query()->create([
            'payroll_run_id' => $run->getKey(),
            'payroll_run_participant_id' => $participant->getKey(),
            'employee_id' => $request->employee_id,
            'source_type' => 'leave_request',
            'source_id' => $request->getKey(),
            'pay_item_code' => $payItemCode,
            'label' => $leaveType->name,
            'input_type' => PayrollInput::TYPE_DEDUCTION,
            'quantity' => (float) $request->quantity,
            'rate' => null,
            'amount' => 0,
            'currency' => $run->currency,
            'occurred_on' => $request->starts_on,
            'metadata' => [
                'leave_type_code' => $leaveType->code,
                'leave_ledger_entry_id' => $entry->getKey(),
                'leave_unit' => $request->unit,
                'audit_tag' => $leaveType->audit_tag,
            ],
        ]);
    }

    private function findOpenRunFor(LeaveRequest $request): ?PayrollRun
    {
        return PayrollRun::query()
            ->where('company_id', $request->company_id)
            ->whereIn('status', self::OPEN_RUN_STATUSES)
            ->whereHas('period', function ($q) use ($request): void {
                $q->where('starts_on', '<=', $request->starts_on)
                    ->where('ends_on', '>=', $request->starts_on);
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
