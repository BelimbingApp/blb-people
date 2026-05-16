<?php

namespace App\Modules\People\Payroll\Listeners;

use App\Modules\People\Leave\Events\LeaveApplied;
use App\Modules\People\Leave\Models\LeaveType;
use App\Modules\People\Payroll\Contracts\Intake\PayrollContributionPayload;
use App\Modules\People\Payroll\Services\PayrollContributionIntake;

/**
 * Translates a LeaveApplied event into a payroll contribution when the
 * leave type interacts with payroll and carries a pay-item code.
 *
 * Phase 1 of plan 13: reads `payroll_pay_item_code` directly off the
 * LeaveType row. A future phase will move this to a Payroll-owned
 * mapping table (mirror of the attendance allowance mapping).
 */
class RecordLeaveContribution
{
    public const SOURCE_TYPE = 'leave_request';

    public function __construct(
        private readonly PayrollContributionIntake $intake,
    ) {}

    public function handle(LeaveApplied $event): void
    {
        $leaveType = LeaveType::query()->find($event->leaveTypeId);
        if ($leaveType === null || ! $leaveType->interacts_with_payroll) {
            return;
        }

        $payItemCode = $leaveType->payroll_pay_item_code;
        if (! is_string($payItemCode) || $payItemCode === '') {
            return;
        }

        $this->intake->ingest(new PayrollContributionPayload(
            sourceType: self::SOURCE_TYPE,
            sourceId: $event->leaveRequestId,
            payItemCode: $payItemCode,
            periodAnchor: $event->occurredOn,
            companyId: $event->companyId,
            employeeId: $event->employeeId,
            currency: 'MYR',
            occurredOn: $event->occurredOn,
            inputType: 'deduction',
            amount: 0.0,
            quantity: $event->quantity,
            rate: null,
            label: (string) $leaveType->name,
            metadata: [
                'leave_type_code' => $leaveType->code,
                'leave_ledger_entry_id' => $event->leaveBalanceLedgerEntryId,
                'leave_unit' => $event->unit,
                'audit_tag' => $leaveType->audit_tag,
            ],
        ));
    }
}
