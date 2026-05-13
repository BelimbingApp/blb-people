<?php

namespace App\Modules\People\Claim\Services;

use App\Modules\People\Claim\Exceptions\ClaimRequestLifecycleException;
use App\Modules\People\Claim\Models\ClaimRequest;
use App\Modules\People\Claim\Models\ClaimRequestAuditEvent;
use Illuminate\Support\Facades\DB;

class RequestClaimMoreInfoService
{
    public function __construct(
        private readonly ClaimNotificationDispatcher $notifications,
    ) {}

    public function requestMoreInfo(ClaimRequest $request, ?int $actorUserId = null, ?string $reason = null): ClaimRequest
    {
        if (! in_array($request->status, [ClaimRequest::STATUS_SUBMITTED, ClaimRequest::STATUS_RESUBMITTED], true)) {
            throw ClaimRequestLifecycleException::invalidStatus((int) $request->getKey(), $request->status, 'sent back for more information');
        }

        return DB::transaction(function () use ($request, $actorUserId, $reason): ClaimRequest {
            $fromStatus = $request->status;
            $request->status = ClaimRequest::STATUS_NEEDS_MORE_INFO;
            $request->decision_reason = $reason;
            $request->save();

            ClaimRequestAuditEvent::query()->create([
                'claim_request_id' => $request->getKey(),
                'from_status' => $fromStatus,
                'to_status' => ClaimRequest::STATUS_NEEDS_MORE_INFO,
                'actor_user_id' => $actorUserId,
                'reason' => $reason,
                'occurred_at' => now(),
                'metadata' => ['action' => 'request_more_info'],
            ]);

            $request = $request->refresh();
            $this->notifications->dispatch(ClaimNotificationDispatcher::EVENT_MORE_INFO, $request, [
                'actor_user_id' => $actorUserId,
                'reason' => $reason,
            ]);

            return $request;
        });
    }
}
