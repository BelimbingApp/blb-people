<?php

namespace App\Modules\People\Claim\Services;

use App\Modules\People\Claim\Exceptions\ClaimRequestLifecycleException;
use App\Modules\People\Claim\Models\ClaimRequest;
use App\Modules\People\Claim\Models\ClaimRequestAuditEvent;
use Illuminate\Support\Facades\DB;

class RejectClaimRequestService
{
    public function reject(ClaimRequest $request, ?int $actorUserId = null, ?string $reason = null): ClaimRequest
    {
        if (! in_array($request->status, [ClaimRequest::STATUS_SUBMITTED, ClaimRequest::STATUS_RESUBMITTED], true)) {
            throw ClaimRequestLifecycleException::invalidStatus((int) $request->getKey(), $request->status, 'rejected');
        }

        return DB::transaction(function () use ($request, $actorUserId, $reason): ClaimRequest {
            $fromStatus = $request->status;
            $request->status = ClaimRequest::STATUS_REJECTED;
            $request->rejected_at = now();
            $request->decision_reason = $reason;
            $request->save();

            ClaimRequestAuditEvent::query()->create([
                'claim_request_id' => $request->getKey(),
                'from_status' => $fromStatus,
                'to_status' => ClaimRequest::STATUS_REJECTED,
                'actor_user_id' => $actorUserId,
                'reason' => $reason,
                'occurred_at' => now(),
            ]);

            return $request->refresh();
        });
    }
}
