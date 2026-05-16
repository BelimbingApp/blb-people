<?php

namespace App\Modules\People\Payroll\Listeners;

use App\Modules\People\Leave\Events\LeaveApplied;
use App\Modules\People\Leave\Models\LeaveType;
use App\Modules\People\Payroll\Contracts\Intake\PayrollContributionPayload;
use App\Modules\People\Payroll\Models\PayrollLeaveTypePayItem;
use App\Modules\People\Payroll\Services\PayrollContributionIntake;

/**
 * Translates a LeaveApplied event into a payroll contribution when the
 * leave type interacts with payroll and an active pay-item mapping
 * exists.
 *
 * The mapping table (`people_payroll_leave_type_pay_items`) replaces
 * the previous `LeaveType.payroll_pay_item_code` column — Plan 16
 * moved it to the Payroll side with effective-dating.
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

        $payItemCode = $this->resolvePayItemCode($event);
        if ($payItemCode === null) {
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

    private function resolvePayItemCode(LeaveApplied $event): ?string
    {
        $occurredOn = $event->occurredOn->format('Y-m-d');

        $mapping = PayrollLeaveTypePayItem::query()
            ->where('leave_type_id', $event->leaveTypeId)
            ->where('effective_from', '<=', $occurredOn)
            ->where(function ($query) use ($occurredOn): void {
                $query->whereNull('effective_to')
                    ->orWhere('effective_to', '>', $occurredOn);
            })
            ->orderByDesc('effective_from')
            ->first();

        $payItemCode = $mapping?->payroll_pay_item_code;

        return is_string($payItemCode) && $payItemCode !== '' ? $payItemCode : null;
    }
}
