<?php

namespace App\Domains\People\Leave\Data;

use App\Domains\People\Leave\Models\LeaveEntitlementPolicy;
use App\Domains\People\Leave\Models\LeaveRequestPolicy;

final readonly class LeaveLedgerEntryPolicySnapshot
{
    public function __construct(
        public ?LeaveEntitlementPolicy $entitlement = null,
        public ?LeaveRequestPolicy $request = null,
    ) {}
}
