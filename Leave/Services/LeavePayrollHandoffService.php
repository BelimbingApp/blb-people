<?php

namespace App\Modules\People\Leave\Services;

use App\Modules\People\Leave\Models\LeaveBalanceLedgerEntry;
use App\Modules\People\Leave\Models\LeaveRequest;
use App\Modules\People\Payroll\Contracts\Intake\PayrollContributionOutcome;
use App\Modules\People\Payroll\Contracts\Intake\PayrollContributionPayload;
use App\Modules\People\Payroll\Services\PayrollContributionIntake;
use DateTimeImmutable;

/**
 * Hands applied leave to Payroll via the neutral intake contract.
 *
 * Each applied unpaid-leave (or other payroll-interacting leave) request
 * produces one PayrollContributionPayload keyed on
 * (source_type='leave_request', source_id=request.id, pay_item_code,
 * period_anchor=starts_on). Payroll decides whether to materialise a
 * PayrollInput row immediately or hold it as pending until a run opens.
 */
class LeavePayrollHandoffService
{
    public const SOURCE_TYPE = 'leave_request';

    public function __construct(
        private readonly PayrollContributionIntake $intake,
    ) {}

    public function onLeaveApplied(LeaveRequest $request, LeaveBalanceLedgerEntry $entry): ?PayrollContributionOutcome
    {
        $leaveType = $request->leaveType;
        if ($leaveType === null || ! $leaveType->interacts_with_payroll) {
            return null;
        }

        $payItemCode = $leaveType->payroll_pay_item_code;
        if ($payItemCode === null) {
            return null;
        }

        $anchor = $this->anchorOf($request);

        return $this->intake->ingest(new PayrollContributionPayload(
            sourceType: self::SOURCE_TYPE,
            sourceId: (int) $request->getKey(),
            payItemCode: $payItemCode,
            periodAnchor: $anchor,
            companyId: (int) $request->company_id,
            employeeId: (int) $request->employee_id,
            currency: 'MYR',
            occurredOn: $anchor,
            inputType: 'deduction',
            amount: 0.0,
            quantity: (float) $request->quantity,
            rate: null,
            label: (string) $leaveType->name,
            metadata: [
                'leave_type_code' => $leaveType->code,
                'leave_ledger_entry_id' => $entry->getKey(),
                'leave_unit' => $request->unit,
                'audit_tag' => $leaveType->audit_tag,
            ],
        ));
    }

    private function anchorOf(LeaveRequest $request): DateTimeImmutable
    {
        $starts = $request->starts_on instanceof \DateTimeInterface
            ? $request->starts_on->format('Y-m-d')
            : (string) $request->starts_on;

        return new DateTimeImmutable($starts);
    }
}
