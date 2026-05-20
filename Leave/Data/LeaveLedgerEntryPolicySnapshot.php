<?php

namespace App\Modules\People\Leave\Data;

use App\Modules\People\Leave\Models\LeaveEntitlementPolicy;
use App\Modules\People\Leave\Models\LeaveRequestPolicy;

final readonly class LeaveLedgerEntryPolicySnapshot
{
    public function __construct(
        public ?LeaveEntitlementPolicy $entitlement = null,
        public ?LeaveRequestPolicy $request = null,
    ) {}
}
