<?php

namespace App\Modules\People\Payroll\Contracts;

use App\Modules\People\Payroll\Data\PayrollCalculationContext;
use App\Modules\People\Payroll\Data\PayrollCalculationResult;

interface CalculatesPayrollRun
{
    public function calculate(PayrollCalculationContext $context): PayrollCalculationResult;
}
