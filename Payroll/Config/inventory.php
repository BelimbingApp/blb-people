<?php

use App\Modules\People\Payroll\Inventory\PayrollInventoryContributionProvider;

return [
    /*
    | Software Inventory contribution providers for the Payroll module.
    |
    | Discovered from `Config/inventory.php` by the Base
    | InventoryContributionDiscoveryService. The Payroll provider reports its
    | registered country packs so they appear as contributions on the Modules
    | screen, under the bundle that delivers `people/payroll`.
    */
    'contribution_providers' => [
        PayrollInventoryContributionProvider::class,
    ],
];
