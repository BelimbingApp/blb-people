<?php

namespace App\Domains\People\Payroll\Contracts;

use App\Domains\People\Payroll\Data\ProfileSchema;

interface ProvidesPayrollProfileSchemas
{
    public function employerSchema(): ProfileSchema;

    public function employeeSchema(): ProfileSchema;
}
