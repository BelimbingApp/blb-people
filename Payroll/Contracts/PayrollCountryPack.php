<?php

namespace App\Modules\People\Payroll\Contracts;

use App\Modules\People\Payroll\Data\CountryPackManifest;

interface PayrollCountryPack
{
    public function manifest(): CountryPackManifest;

    public function profileSchemas(): ProvidesPayrollProfileSchemas;

    public function payItemClassifier(): ClassifiesPayrollPayItems;

    public function calculator(): CalculatesPayrollRun;

    public function exports(): ProvidesPayrollExports;
}
