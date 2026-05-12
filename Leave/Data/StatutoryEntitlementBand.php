<?php

namespace App\Modules\People\Leave\Data;

class StatutoryEntitlementBand
{
    public function __construct(
        public readonly float $minYearsOfService,
        public readonly ?float $maxYearsOfService,
        public readonly float $entitlementDays,
    ) {}
}
