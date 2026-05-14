<?php

namespace App\Modules\People\Claim\Services;

use App\Modules\People\Claim\Exceptions\ClaimRequestLifecycleException;
use App\Modules\People\Claim\Models\ClaimAssignmentLine;
use App\Modules\People\Claim\Models\ClaimEntitlementUsageEntry;
use App\Modules\People\Claim\Models\ClaimRequest;
use App\Modules\People\Claim\Models\ClaimRequestAuditEvent;
use Illuminate\Support\Facades\DB;

class ApproveClaimRequestService
{
    public function __construct(
        private readonly ClaimPayrollHandoffService $payrollHandoff,
        private readonly ClaimNotificationDispatcher $notifications,
        private readonly ClaimPolicyEvaluationService $policyEvaluation,
    ) {}

    public function approve(ClaimRequest $request, ?int $actorUserId = null, ?string $reason = null): ClaimRequest
    {
        if (! in_array($request->status, [ClaimRequest::STATUS_SUBMITTED, ClaimRequest::STATUS_RESUBMITTED], true)) {
            throw ClaimRequestLifecycleException::invalidStatus((int) $request->getKey(), $request->status, 'approved');
        }

        return DB::transaction(function () use ($request, $actorUserId, $reason): ClaimRequest {
            $fromStatus = $request->status;
            $request->loadMissing(['lines.assignmentLine', 'lines.policy', 'lines.type']);

            foreach ($request->lines as $line) {
                $approving = (float) $line->requested_amount;
                $blocking = $this->policyEvaluation->evaluateAtApproval(
                    $line,
                    $approving,
                    $this->combinedClaimTypeIdsFor($line->assignmentLine),
                );

                if ($blocking !== []) {
                    throw ClaimRequestLifecycleException::invalidSubmission(
                        'Approval blocked by current policy state: '.implode(' ', $blocking),
                    );
                }

                $line->approved_amount = $approving;
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

            $this->notifications->dispatch(ClaimNotificationDispatcher::EVENT_APPROVED, $request, [
                'actor_user_id' => $actorUserId,
                'reason' => $reason,
            ]);

            $this->payrollHandoff->queueApprovedRequest($request, $actorUserId);

            return $request->refresh();
        });
    }

    /** @return list<int> */
    private function combinedClaimTypeIdsFor(?ClaimAssignmentLine $assignmentLine): array
    {
        if ($assignmentLine === null
            || ! (bool) $assignmentLine->uses_combined_cap
            || $assignmentLine->combine_tag === null
            || trim((string) $assignmentLine->combine_tag) === ''
        ) {
            return [];
        }

        return ClaimAssignmentLine::query()
            ->where('claim_assignment_id', $assignmentLine->claim_assignment_id)
            ->where('status', ClaimAssignmentLine::STATUS_ACTIVE)
            ->where('uses_combined_cap', true)
            ->where('combine_tag', $assignmentLine->combine_tag)
            ->pluck('claim_type_id')
            ->map(fn ($id): int => (int) $id)
            ->values()
            ->all();
    }
}
