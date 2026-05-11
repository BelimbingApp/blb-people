<?php

namespace App\Modules\People\Payroll\Contracts;

use App\Modules\People\Payroll\Data\PayrollExportDefinition;

interface ProvidesPayrollExports
{
    /**
     * @return list<PayrollExportDefinition>
     */
    public function definitions(): array;
}
