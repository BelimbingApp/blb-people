<?php

namespace App\Modules\People\Leave\Services;

use App\Modules\People\Leave\Exceptions\LeaveRequestLifecycleException;
use App\Modules\People\Leave\Models\LeaveRequest;
use App\Modules\People\Leave\Models\LeaveRequestAuditEvent;
use Illuminate\Support\Facades\DB;

class ApproveLeaveRequestService
{
    public function __construct(
        private readonly ApplyLeaveRequestService $apply,
        private readonly LeaveNotificationDispatcher $notifications,
    ) {}

    public function approve(LeaveRequest $request, ?int $actorUserId = null, ?string $reason = null, bool $autoApply = true): LeaveRequest
    {
        if ($request->status !== LeaveRequest::STATUS_SUBMITTED) {
            throw LeaveRequestLifecycleException::invalidStatus(
                (int) $request->getKey(),
                $request->status,
                'approved',
            );
        }

        $approved = DB::transaction(function () use ($request, $actorUserId, $reason): LeaveRequest {
            $request->status = LeaveRequest::STATUS_APPROVED;
            $request->approved_at = now();
            $request->save();

            LeaveRequestAuditEvent::query()->create([
                'leave_request_id' => $request->getKey(),
                'from_status' => LeaveRequest::STATUS_SUBMITTED,
                'to_status' => LeaveRequest::STATUS_APPROVED,
                'actor_user_id' => $actorUserId,
                'reason' => $reason,
                'occurred_at' => now(),
            ]);

            $this->notifications->dispatch(LeaveNotificationDispatcher::EVENT_APPROVED, $request);

            return $request;
        });

        if ($autoApply) {
            return $this->apply->apply($approved, $actorUserId);
        }

        return $approved;
    }
}
