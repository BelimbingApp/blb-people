<?php

namespace App\Modules\People\Leave\Contracts;

use App\Modules\People\Leave\Data\LeaveValidationIssue;
use App\Modules\People\Leave\Models\LeaveEntitlementPolicy;

interface ValidatesLeaveAgainstStatute
{
    /**
     * Validate a configured entitlement policy against the country's statutory floor.
     *
     * @return list<LeaveValidationIssue>
     */
    public function validateEntitlementPolicy(LeaveEntitlementPolicy $policy): array;
}
