<?php

use App\Domains\People\Payroll\CountryPacks\Malaysia\MalaysiaPayrollCountryPack;

return [
    /*
    | Payroll country packs contributed to PayrollCountryPackRegistry.
    |
    | Discovered from `Config/payroll.php` across modules and extensions by
    | PayrollCountryPackDiscoveryService. The Payroll engine ships Malaysia as
    | its built-in reference pack; Domains and Extensions contribute other
    | countries by declaring their pack classes here.
    */
    'country_packs' => [
        MalaysiaPayrollCountryPack::class,
    ],
];
