<?php

namespace App\Modules\People\Claim\Services;

use App\Modules\People\Claim\Exceptions\ClaimRequestLifecycleException;
use App\Modules\People\Claim\Models\ClaimEntitlementUsageEntry;
use App\Modules\People\Claim\Models\ClaimRequest;
use App\Modules\People\Claim\Models\ClaimRequestAuditEvent;
use Illuminate\Support\Facades\DB;

/**
 * Finalises a claim request once payroll has paid out the approved amount.
 *
 * Moves status from approved or queued_for_payroll → reimbursed, copies approved_amount into
 * reimbursed_amount per line, writes an audit event, records reimbursed usage entries, and
 * dispatches the reimbursement notification. Idempotent re-runs are refused.
 */
class ReimburseClaimRequestService
{
    public function __construct(
        private readonly ClaimNotificationDispatcher $notifications,
    ) {}

    public function reimburse(ClaimRequest $request, ?int $actorUserId = null, ?string $reason = null): ClaimRequest
    {
        if (! in_array($request->status, [
            ClaimRequest::STATUS_APPROVED,
            ClaimRequest::STATUS_QUEUED_FOR_PAYROLL,
        ], true)) {
            throw ClaimRequestLifecycleException::invalidStatus((int) $request->getKey(), $request->status, 'reimbursed');
        }

        return DB::transaction(function () use ($request, $actorUserId, $reason): ClaimRequest {
            $fromStatus = $request->status;
            $request->loadMissing('lines');

            $reimbursedTotal = 0.0;
            foreach ($request->lines as $line) {
                $line->reimbursed_amount = $line->approved_amount;
                $line->save();
                $reimbursedTotal += (float) $line->reimbursed_amount;

                ClaimEntitlementUsageEntry::query()->create([
                    'company_id' => $request->company_id,
                    'employee_id' => $request->employee_id,
                    'claim_type_id' => $line->claim_type_id,
                    'claim_policy_id' => $line->claim_policy_id,
                    'claim_line_id' => $line->getKey(),
                    'claim_year' => (int) $line->incurred_on->format('Y'),
                    'entry_type' => ClaimEntitlementUsageEntry::ENTRY_REIMBURSED,
                    'amount' => $line->reimbursed_amount,
                    'currency' => $line->currency,
                    'source_type' => 'claim_request',
                    'source_id' => $request->getKey(),
                    'occurred_on' => now()->toDateString(),
                    'note' => $reason,
                    'metadata' => ['source' => 'claim-workbench'],
                ]);
            }

            $request->reimbursed_amount = $reimbursedTotal;
            $request->status = ClaimRequest::STATUS_REIMBURSED;
            $request->reimbursed_at = now();
            $request->decision_reason = $reason ?? $request->decision_reason;
            $request->save();

            ClaimRequestAuditEvent::query()->create([
                'claim_request_id' => $request->getKey(),
                'from_status' => $fromStatus,
                'to_status' => ClaimRequest::STATUS_REIMBURSED,
                'actor_user_id' => $actorUserId,
                'reason' => $reason,
                'occurred_at' => now(),
                'metadata' => ['reimbursed_amount' => $reimbursedTotal],
            ]);

            $request = $request->refresh();
            $this->notifications->dispatch(ClaimNotificationDispatcher::EVENT_REIMBURSED, $request, [
                'actor_user_id' => $actorUserId,
                'reason' => $reason,
            ]);

            return $request;
        });
    }
}
