<?php

namespace App\Modules\People\Leave\Services;

use App\Modules\People\Leave\Contracts\RoutesLeaveApprovals;
use App\Modules\People\Leave\Data\LeaveApprovalIntent;
use App\Modules\People\Leave\Models\LeaveRequest;
use Illuminate\Support\Facades\Log;

/**
 * Default approval router used until the Workflow module's multi-tier-by-type
 * routing gap (see plan Phase 0) is closed.
 *
 * Records the intent on the request as metadata so a real router can pick it
 * up; does not auto-approve.
 */
class NullLeaveApprovalRouter implements RoutesLeaveApprovals
{
    public function route(LeaveRequest $request, LeaveApprovalIntent $intent): void
    {
        $metadata = $request->metadata ?? [];
        $metadata['pending_approval_intent'] = [
            'depth' => $intent->approvalDepth,
            'employment_group_code' => $intent->employmentGroupCode,
            'days_threshold' => $intent->daysThreshold,
            'approver_chain' => $intent->approverChain,
            'queued_at' => now()->toIso8601String(),
        ];

        $request->metadata = $metadata;
        $request->approval_workflow_ref = 'null-router:queued';
        $request->saveQuietly();

        Log::info('leave.approval_routing.queued', [
            'leave_request_id' => $request->getKey(),
            'depth' => $intent->approvalDepth,
        ]);
    }
}
