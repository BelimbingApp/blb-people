<?php

namespace App\Modules\People\Leave\Services;

use App\Modules\People\Leave\Exceptions\LeaveRequestLifecycleException;
use App\Modules\People\Leave\Models\LeaveRequest;
use App\Modules\People\Leave\Models\LeaveRequestAuditEvent;
use Illuminate\Support\Facades\DB;

class RejectLeaveRequestService
{
    public function __construct(
        private readonly LeaveNotificationDispatcher $notifications,
    ) {}

    public function reject(LeaveRequest $request, ?int $actorUserId = null, ?string $reason = null): LeaveRequest
    {
        if ($request->status !== LeaveRequest::STATUS_SUBMITTED) {
            throw LeaveRequestLifecycleException::invalidStatus(
                (int) $request->getKey(),
                $request->status,
                'rejected',
            );
        }

        return DB::transaction(function () use ($request, $actorUserId, $reason): LeaveRequest {
            $request->status = LeaveRequest::STATUS_REJECTED;
            $request->rejected_at = now();
            $request->rejection_reason = $reason;
            $request->save();

            LeaveRequestAuditEvent::query()->create([
                'leave_request_id' => $request->getKey(),
                'from_status' => LeaveRequest::STATUS_SUBMITTED,
                'to_status' => LeaveRequest::STATUS_REJECTED,
                'actor_user_id' => $actorUserId,
                'reason' => $reason,
                'occurred_at' => now(),
            ]);

            $this->notifications->dispatch(LeaveNotificationDispatcher::EVENT_REJECTED, $request);

            return $request;
        });
    }
}
