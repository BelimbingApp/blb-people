<?php

namespace App\Domains\People\Payroll\Contracts;

use App\Domains\People\Payroll\Data\PayrollExportDefinition;

interface ProvidesPayrollExports
{
    /**
     * @return list<PayrollExportDefinition>
     */
    public function definitions(): array;
}
