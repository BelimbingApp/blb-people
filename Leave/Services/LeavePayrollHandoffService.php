<?php

namespace App\Modules\People\Leave\Services;

use App\Modules\People\Leave\Models\LeaveBalanceLedgerEntry;
use App\Modules\People\Leave\Models\LeaveRequest;
use App\Modules\People\Payroll\Models\PayrollInput;

/**
 * Bridges Leave Core → Payroll Core via the neutral PayrollInput surface.
 *
 * Each unpaid-leave or encashment row carries the source leave request ID;
 * the same (source_type, source_id, payroll_period) tuple is unique so a
 * re-apply cannot double-count. Payroll Core / the country pack remains
 * responsible for classifying these inputs for statutory contributions.
 */
class LeavePayrollHandoffService
{
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

        return PayrollInput::query()->create([
            'payroll_run_id' => null,
            'payroll_run_participant_id' => null,
            'employee_id' => $request->employee_id,
            'source_type' => 'leave_request',
            'source_id' => $request->getKey(),
            'pay_item_code' => $payItemCode,
            'label' => $leaveType->name,
            'input_type' => PayrollInput::TYPE_DEDUCTION,
            'quantity' => (float) $request->quantity,
            'rate' => null,
            'amount' => 0,
            'currency' => 'MYR',
            'occurred_on' => $request->starts_on,
            'metadata' => [
                'leave_type_code' => $leaveType->code,
                'leave_ledger_entry_id' => $entry->getKey(),
                'leave_unit' => $request->unit,
                'audit_tag' => $leaveType->audit_tag,
            ],
        ]);
    }
}
