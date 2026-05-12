<?php

namespace App\Modules\People\Leave\Contracts;

use App\Modules\People\Leave\Data\LeaveApprovalIntent;
use App\Modules\People\Leave\Models\LeaveRequest;

interface RoutesLeaveApprovals
{
    /**
     * Dispatch the request to the approval engine.
     *
     * Implementations should be idempotent; calling twice on the same request
     * must not enqueue duplicate approval workflows.
     */
    public function route(LeaveRequest $request, LeaveApprovalIntent $intent): void;
}
