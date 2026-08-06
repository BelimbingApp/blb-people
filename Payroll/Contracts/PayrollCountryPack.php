<?php

namespace App\Domains\People\Payroll\Contracts;

use App\Domains\People\Payroll\Data\CountryPackManifest;

interface PayrollCountryPack
{
    public function manifest(): CountryPackManifest;

    public function profileSchemas(): ProvidesPayrollProfileSchemas;

    public function payItemClassifier(): ClassifiesPayrollPayItems;

    public function calculator(): CalculatesPayrollRun;

    public function exports(): ProvidesPayrollExports;
}
