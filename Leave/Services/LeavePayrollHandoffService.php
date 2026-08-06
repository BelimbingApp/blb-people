<?php

namespace App\Domains\People\Leave\Services;

use App\Domains\People\Leave\Events\LeaveApplied;
use App\Domains\People\Leave\Models\LeaveBalanceLedgerEntry;
use App\Domains\People\Leave\Models\LeaveRequest;
use DateTimeImmutable;

/**
 * Dispatches LeaveApplied for an applied leave request so downstream
 * consumers (the Payroll module, audit sinks) can pick up the fact.
 *
 * Plan 13 Phase 1 — the dispatching producer side. Whether the leave
 * type actually emits a payroll contribution is decided by the
 * listener, not here.
 */
class LeavePayrollHandoffService
{
    public const SOURCE_TYPE = 'leave_request';

    public function onLeaveApplied(LeaveRequest $request, LeaveBalanceLedgerEntry $entry): bool
    {
        $leaveType = $request->leaveType;
        if ($leaveType === null || ! $leaveType->interacts_with_payroll) {
            return false;
        }

        event(new LeaveApplied(
            companyId: (int) $request->company_id,
            employeeId: (int) $request->employee_id,
            leaveRequestId: (int) $request->getKey(),
            leaveTypeId: (int) $leaveType->getKey(),
            leaveBalanceLedgerEntryId: (int) $entry->getKey(),
            occurredOn: $this->anchorOf($request),
            quantity: (float) $request->quantity,
            unit: (string) $request->unit,
        ));

        return true;
    }

    private function anchorOf(LeaveRequest $request): DateTimeImmutable
    {
        $starts = $request->starts_on instanceof \DateTimeInterface
            ? $request->starts_on->format('Y-m-d')
            : (string) $request->starts_on;

        return new DateTimeImmutable($starts);
    }
}
