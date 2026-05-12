<?php

namespace App\Modules\People\Leave\Services;

use App\Modules\People\Leave\Models\LeaveBalanceLedgerEntry;
use App\Modules\People\Leave\Models\LeaveRequest;
use App\Modules\People\Leave\Models\LeaveRequestAuditEvent;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class CancelLeaveRequestService
{
    private const CANCELLABLE_STATUSES = [
        LeaveRequest::STATUS_DRAFT,
        LeaveRequest::STATUS_SUBMITTED,
        LeaveRequest::STATUS_APPROVED,
    ];

    public function __construct(
        private readonly LeaveBalanceLedgerService $ledger,
        private readonly LeaveNotificationDispatcher $notifications,
    ) {}

    public function cancel(LeaveRequest $request, ?int $actorUserId = null, ?string $reason = null): LeaveRequest
    {
        if (! in_array($request->status, self::CANCELLABLE_STATUSES, true)) {
            throw new RuntimeException(sprintf(
                'Leave request %d in status [%s] is not cancellable.',
                $request->getKey(),
                $request->status,
            ));
        }

        return DB::transaction(function () use ($request, $actorUserId, $reason): LeaveRequest {
            $fromStatus = $request->status;

            $request->status = LeaveRequest::STATUS_CANCELLED;
            $request->cancelled_at = now();
            $request->save();

            LeaveRequestAuditEvent::query()->create([
                'leave_request_id' => $request->getKey(),
                'from_status' => $fromStatus,
                'to_status' => LeaveRequest::STATUS_CANCELLED,
                'actor_user_id' => $actorUserId,
                'reason' => $reason,
                'occurred_at' => now(),
            ]);

            $this->notifications->dispatch(LeaveNotificationDispatcher::EVENT_CANCELLED, $request);

            return $request;
        });
    }
}
