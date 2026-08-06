<?php

namespace App\Domains\People\Payroll\Contracts;

use App\Domains\People\Payroll\Data\PayrollCalculationContext;
use App\Domains\People\Payroll\Data\PayrollCalculationResult;

interface CalculatesPayrollRun
{
    public function calculate(PayrollCalculationContext $context): PayrollCalculationResult;
}
