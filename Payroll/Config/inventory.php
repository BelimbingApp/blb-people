<?php

use App\Domains\People\Payroll\Inventory\PayrollInventoryContributionProvider;

return [
    /*
    | Software Inventory contribution providers for the Payroll module.
    |
    | Discovered from `Config/inventory.php` by the Base
    | InventoryContributionDiscoveryService. The Payroll provider reports its
    | registered country packs so they appear as contributions on the Domains
    | screen, under the Domain that contains `people/payroll`.
    */
    'contribution_providers' => [
        PayrollInventoryContributionProvider::class,
    ],
];
