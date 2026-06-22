<?php

use App\Modules\People\Payroll\CountryPacks\Malaysia\MalaysiaPayrollCountryPack;

return [
    /*
    | Payroll country packs contributed to PayrollCountryPackRegistry.
    |
    | Discovered from `Config/payroll.php` across modules and extensions by
    | PayrollCountryPackDiscoveryService. The Payroll engine ships Malaysia as
    | its built-in reference pack; other countries arrive as add-on bundles that
    | declare their own pack class here.
    */
    'country_packs' => [
        MalaysiaPayrollCountryPack::class,
    ],
];
