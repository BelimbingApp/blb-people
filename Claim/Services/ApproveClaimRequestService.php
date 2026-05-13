<?php

namespace App\Modules\People\Claim\Services;

use App\Modules\People\Claim\Exceptions\ClaimRequestLifecycleException;
use App\Modules\People\Claim\Models\ClaimEntitlementUsageEntry;
use App\Modules\People\Claim\Models\ClaimRequest;
use App\Modules\People\Claim\Models\ClaimRequestAuditEvent;
use Illuminate\Support\Facades\DB;

class ApproveClaimRequestService
{
    public function approve(ClaimRequest $request, ?int $actorUserId = null, ?string $reason = null): ClaimRequest
    {
        if (! in_array($request->status, [ClaimRequest::STATUS_SUBMITTED, ClaimRequest::STATUS_RESUBMITTED], true)) {
            throw ClaimRequestLifecycleException::invalidStatus((int) $request->getKey(), $request->status, 'approved');
        }

        return DB::transaction(function () use ($request, $actorUserId, $reason): ClaimRequest {
            $fromStatus = $request->status;
            $request->loadMissing('lines');

            foreach ($request->lines as $line) {
                $line->approved_amount = $line->requested_amount;
                $line->save();

                ClaimEntitlementUsageEntry::query()->create([
                    'company_id' => $request->company_id,
                    'employee_id' => $request->employee_id,
                    'claim_type_id' => $line->claim_type_id,
                    'claim_policy_id' => $line->claim_policy_id,
                    'claim_line_id' => $line->getKey(),
                    'claim_year' => (int) $line->incurred_on->format('Y'),
                    'entry_type' => ClaimEntitlementUsageEntry::ENTRY_APPROVED,
                    'amount' => $line->approved_amount,
                    'currency' => $line->currency,
                    'source_type' => 'claim_request',
                    'source_id' => $request->getKey(),
                    'occurred_on' => now()->toDateString(),
                    'note' => $reason,
                    'metadata' => ['source' => 'claim-workbench'],
                ]);
            }

            $request->approved_amount = $request->lines->sum(fn ($line): float => (float) $line->approved_amount);
            $request->status = ClaimRequest::STATUS_APPROVED;
            $request->approved_at = now();
            $request->decision_reason = $reason;
            $request->save();

            ClaimRequestAuditEvent::query()->create([
                'claim_request_id' => $request->getKey(),
                'from_status' => $fromStatus,
                'to_status' => ClaimRequest::STATUS_APPROVED,
                'actor_user_id' => $actorUserId,
                'reason' => $reason,
                'occurred_at' => now(),
                'metadata' => ['approved_amount' => $request->approved_amount],
            ]);

            return $request->refresh();
        });
    }
}
