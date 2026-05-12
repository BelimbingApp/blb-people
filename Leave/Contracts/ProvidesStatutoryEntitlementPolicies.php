<?php

namespace App\Modules\People\Leave\Contracts;

use App\Modules\People\Leave\Data\StatutoryEntitlementPolicy;

interface ProvidesStatutoryEntitlementPolicies
{
    /** @return list<StatutoryEntitlementPolicy> */
    public function statutoryEntitlementPolicies(): array;
}
