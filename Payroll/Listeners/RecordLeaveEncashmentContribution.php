<?php

namespace App\Modules\People\Payroll\Listeners;

use App\Modules\People\Leave\Events\LeaveEncashed;
use App\Modules\People\Leave\Models\LeaveType;
use App\Modules\People\Payroll\Contracts\Intake\PayrollContributionPayload;
use App\Modules\People\Payroll\Services\PayrollContributionIntake;

/**
 * Translates a LeaveEncashed event into a payroll contribution. The
 * encashment pay item is currently fixed to the LeaveType constant
 * (`PAYROLL_CODE_LEAVE_ENCASHMENT`). When the encashment-rule mapping
 * table lands, the listener will resolve it from there instead.
 */
class RecordLeaveEncashmentContribution
{
    public const SOURCE_TYPE = 'leave_encashment';

    public function __construct(
        private readonly PayrollContributionIntake $intake,
    ) {}

    public function handle(LeaveEncashed $event): void
    {
        $leaveType = LeaveType::query()->find($event->leaveTypeId);
        if ($leaveType === null) {
            return;
        }

        $this->intake->ingest(new PayrollContributionPayload(
            sourceType: self::SOURCE_TYPE,
            sourceId: $event->leaveBalanceLedgerEntryId,
            payItemCode: LeaveType::PAYROLL_CODE_LEAVE_ENCASHMENT,
            periodAnchor: $event->occurredOn,
            companyId: $event->companyId,
            employeeId: $event->employeeId,
            currency: $event->currency,
            occurredOn: $event->occurredOn,
            inputType: 'earning',
            amount: 0.0,
            quantity: $event->days,
            rate: null,
            label: $leaveType->name.' encashment',
            metadata: [
                'leave_type_code' => $leaveType->code,
                'leave_ledger_entry_id' => $event->leaveBalanceLedgerEntryId,
                'leave_year' => $event->leaveYear,
            ],
        ));
    }
}
