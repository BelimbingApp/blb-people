<?php

namespace App\Modules\People\Payroll\CountryPacks\Malaysia;

use App\Modules\People\Payroll\Contracts\CalculatesPayrollRun;
use App\Modules\People\Payroll\Data\PayrollCalculationContext;
use App\Modules\People\Payroll\Data\PayrollCalculationResult;

class MalaysiaPayrollCalculator implements CalculatesPayrollRun
{
    public function calculate(PayrollCalculationContext $context): PayrollCalculationResult
    {
        return new PayrollCalculationResult(metadata: [
            'country_iso' => MalaysiaPayrollCountryPack::COUNTRY_ISO,
            'pack_identifier' => MalaysiaPayrollCountryPack::PACK_IDENTIFIER,
            'pack_version' => MalaysiaPayrollCountryPack::PACK_VERSION,
            'status' => 'skeleton_only',
        ]);
    }
}
