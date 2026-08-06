<?php

namespace App\Domains\People\Leave\Contracts;

use App\Domains\People\Leave\Data\StatutoryEntitlementPolicy;

interface ProvidesStatutoryEntitlementPolicies
{
    /** @return list<StatutoryEntitlementPolicy> */
    public function statutoryEntitlementPolicies(): array;
}
