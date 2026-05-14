<?php

namespace App\Modules\People\Claim\Services;

use App\Modules\People\Claim\Exceptions\ClaimRequestLifecycleException;
use App\Modules\People\Claim\Models\ClaimRequest;
use App\Modules\People\Claim\Models\ClaimRequestAuditEvent;
use Illuminate\Support\Facades\DB;

class CancelClaimRequestService
{
    public function __construct(
        private readonly ClaimNotificationDispatcher $notifications,
        private readonly ClaimPayrollHandoffService $payrollHandoff,
    ) {}

    public function cancel(ClaimRequest $request, ?int $actorUserId = null, ?string $reason = null): ClaimRequest
    {
        if (! in_array($request->status, [
            ClaimRequest::STATUS_DRAFT,
            ClaimRequest::STATUS_SUBMITTED,
            ClaimRequest::STATUS_NEEDS_MORE_INFO,
            ClaimRequest::STATUS_RESUBMITTED,
            ClaimRequest::STATUS_APPROVED,
        ], true)) {
            throw ClaimRequestLifecycleException::invalidStatus((int) $request->getKey(), $request->status, 'cancelled');
        }

        return DB::transaction(function () use ($request, $actorUserId, $reason): ClaimRequest {
            $fromStatus = $request->status;

            // Approved requests may have partial pending payroll contributions when only some lines were
            // materialised. Reverse defensively — no-op when nothing is queued. Cancellable statuses
            // explicitly exclude queued_for_payroll/reimbursed/settled to avoid undoing closed-run payouts.
            if ($fromStatus === ClaimRequest::STATUS_APPROVED) {
                $this->payrollHandoff->reverseRequest($request, $reason ?? 'claim_cancelled');
            }

            $request->status = ClaimRequest::STATUS_CANCELLED;
            $request->cancelled_at = now();
            $request->decision_reason = $reason;
            $request->save();

            ClaimRequestAuditEvent::query()->create([
                'claim_request_id' => $request->getKey(),
                'from_status' => $fromStatus,
                'to_status' => ClaimRequest::STATUS_CANCELLED,
                'actor_user_id' => $actorUserId,
                'reason' => $reason,
                'occurred_at' => now(),
            ]);

            $request = $request->refresh();
            $this->notifications->dispatch(ClaimNotificationDispatcher::EVENT_CANCELLED, $request, [
                'actor_user_id' => $actorUserId,
                'reason' => $reason,
            ]);

            return $request;
        });
    }
}
