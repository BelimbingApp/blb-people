<?php

namespace App\Modules\People\Leave\Data;

use App\Modules\People\Leave\Contracts\RoutesLeaveApprovals;

class LeaveApprovalIntent
{
    /**
     * Describes how a leave request should be routed for approval.
     *
     * Leave Core does not own approval execution — this intent is handed to
     * a {@see RoutesLeaveApprovals}
     * implementation (today: NullLeaveApprovalRouter; later: the Workflow module).
     *
     * @param  list<int|string>  $approverChain  Optional explicit chain (supervisor IDs / role keys).
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public readonly int $leaveRequestId,
        public readonly int $approvalDepth,
        public readonly ?string $employmentGroupCode = null,
        public readonly ?float $daysThreshold = null,
        public readonly array $approverChain = [],
        public readonly array $metadata = [],
    ) {}
}
