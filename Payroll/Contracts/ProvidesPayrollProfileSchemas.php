<?php

namespace App\Modules\People\Payroll\Contracts;

use App\Modules\People\Payroll\Data\ProfileSchema;

interface ProvidesPayrollProfileSchemas
{
    public function employerSchema(): ProfileSchema;

    public function employeeSchema(): ProfileSchema;
}
