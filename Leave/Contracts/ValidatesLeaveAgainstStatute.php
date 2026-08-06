<?php

namespace App\Domains\People\Leave\Contracts;

use App\Domains\People\Leave\Data\LeaveValidationIssue;
use App\Domains\People\Leave\Models\LeaveEntitlementPolicy;

interface ValidatesLeaveAgainstStatute
{
    /**
     * Validate a configured entitlement policy against the country's statutory floor.
     *
     * @return list<LeaveValidationIssue>
     */
    public function validateEntitlementPolicy(LeaveEntitlementPolicy $policy): array;
}
