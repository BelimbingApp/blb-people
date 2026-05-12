<?php

namespace App\Modules\People\Leave\Services;

use App\Modules\People\Leave\Models\LeaveBalanceLedgerEntry;
use App\Modules\People\Leave\Models\LeaveRequest;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Transitions an approved leave request to applied, writing the consuming
 * ledger entry. Also produces a downstream PayrollInput for payroll-interacting
 * leave types (delegated to LeavePayrollHandoffService).
 */
class ApplyLeaveRequestService
{
    public function __construct(
        private readonly LeaveBalanceLedgerService $ledger,
        private readonly LeavePayrollHandoffService $payrollHandoff,
        private readonly LeaveNotificationDispatcher $notifications,
    ) {}

    public function apply(LeaveRequest $request, ?int $actorUserId = null): LeaveRequest
    {
        if ($request->status !== LeaveRequest::STATUS_APPROVED) {
            throw new RuntimeException(sprintf(
                'Leave request %d is in status [%s]; only approved requests can be applied.',
                $request->getKey(),
                $request->status,
            ));
        }

        return DB::transaction(function () use ($request, $actorUserId): LeaveRequest {
            $leaveType = $request->leaveType;
            $entitlementPolicy = $request->assignment?->entitlementPolicy;
            $requestPolicy = $request->assignment?->requestPolicy;

            $entry = $this->ledger->record(
                companyId: $request->company_id,
                employeeId: $request->employee_id,
                leaveTypeId: $request->leave_type_id,
                leaveYear: (int) $request->starts_on->format('Y'),
                entryType: LeaveBalanceLedgerEntry::ENTRY_TAKEN,
                quantity: -1.0 * (float) $request->quantity,
                unit: $request->unit === LeaveRequest::UNIT_HOUR ? 'hour' : 'day',
                sourceType: LeaveBalanceLedgerEntry::SOURCE_LEAVE_REQUEST,
                sourceId: $request->getKey(),
                entitlementPolicy: $entitlementPolicy,
                requestPolicy: $requestPolicy,
                packIdentifier: $leaveType?->pack_identifier,
                packVersion: $leaveType?->pack_version,
                occurredOn: $request->starts_on,
                recordedByUserId: $actorUserId,
                metadata: [
                    'leave_request_status' => LeaveRequest::STATUS_APPLIED,
                    'on_behalf_actor_user_id' => $request->on_behalf_actor_user_id,
                ],
            );

            $request->status = LeaveRequest::STATUS_APPLIED;
            $request->applied_at = now();
            $request->applied_ledger_entry_id = $entry->getKey();
            $request->save();

            if ($leaveType?->interacts_with_payroll) {
                $this->payrollHandoff->onLeaveApplied($request, $entry);
            }

            $this->notifications->dispatch(LeaveNotificationDispatcher::EVENT_APPLIED, $request);

            return $request;
        });
    }
}
