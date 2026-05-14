<?php

namespace App\Modules\People\Leave\Services;

use App\Modules\People\Leave\Exceptions\LeaveRequestLifecycleException;
use App\Modules\People\Leave\Models\LeaveBalanceLedgerEntry;
use App\Modules\People\Leave\Models\LeaveRequest;
use App\Modules\People\Leave\Models\LeaveRequestAuditEvent;
use Illuminate\Support\Facades\DB;

/**
 * Withdraws a leave request after approval (or after it has been applied).
 *
 * For applied requests, writes a reversing `cancelled` ledger entry restoring
 * the consumed balance. Original `taken` entries are never mutated — the
 * ledger remains append-only.
 */
class WithdrawLeaveRequestService
{
    private const WITHDRAWABLE_STATUSES = [
        LeaveRequest::STATUS_APPROVED,
        LeaveRequest::STATUS_APPLIED,
    ];

    public function __construct(
        private readonly LeaveBalanceLedgerService $ledger,
        private readonly LeaveNotificationDispatcher $notifications,
    ) {}

    public function withdraw(LeaveRequest $request, ?int $actorUserId = null, ?string $reason = null): LeaveRequest
    {
        if (! in_array($request->status, self::WITHDRAWABLE_STATUSES, true)) {
            throw LeaveRequestLifecycleException::invalidStatus(
                (int) $request->getKey(),
                $request->status,
                'withdrawn',
            );
        }

        return DB::transaction(function () use ($request, $actorUserId, $reason): LeaveRequest {
            $fromStatus = $request->status;

            if ($fromStatus === LeaveRequest::STATUS_APPLIED) {
                $leaveType = $request->leaveType;
                $entitlementPolicy = $request->assignment?->entitlementPolicy;
                $requestPolicy = $request->assignment?->requestPolicy;

                $this->ledger->record(
                    companyId: $request->company_id,
                    employeeId: $request->employee_id,
                    leaveTypeId: $request->leave_type_id,
                    leaveYear: (int) $request->starts_on->format('Y'),
                    entryType: LeaveBalanceLedgerEntry::ENTRY_CANCELLED,
                    quantity: (float) $request->quantity,
                    unit: $request->unit === LeaveRequest::UNIT_HOUR ? 'hour' : 'day',
                    sourceType: LeaveBalanceLedgerEntry::SOURCE_LEAVE_REQUEST,
                    sourceId: $request->getKey(),
                    entitlementPolicy: $entitlementPolicy,
                    requestPolicy: $requestPolicy,
                    packIdentifier: $leaveType?->pack_identifier,
                    packVersion: $leaveType?->pack_version,
                    occurredOn: $request->starts_on,
                    recordedByUserId: $actorUserId,
                    note: 'Withdrawal of applied leave request',
                    metadata: ['reverses_ledger_entry_id' => $request->applied_ledger_entry_id, 'reason' => $reason],
                );
            }

            $request->status = LeaveRequest::STATUS_WITHDRAWN;
            $request->withdrawn_at = now();
            $request->save();

            LeaveRequestAuditEvent::query()->create([
                'leave_request_id' => $request->getKey(),
                'from_status' => $fromStatus,
                'to_status' => LeaveRequest::STATUS_WITHDRAWN,
                'actor_user_id' => $actorUserId,
                'reason' => $reason,
                'occurred_at' => now(),
            ]);

            $this->notifications->dispatch(LeaveNotificationDispatcher::EVENT_WITHDRAWN, $request);

            return $request;
        });
    }
}
