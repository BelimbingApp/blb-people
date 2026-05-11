<?php

namespace App\Modules\People\Payroll\CountryPacks\Malaysia;

use App\Modules\People\Payroll\Contracts\ClassifiesPayrollPayItems;
use App\Modules\People\Payroll\Models\PayrollPayItem;
use App\Modules\People\Payroll\Services\PayItemClassifier;
use Illuminate\Support\Carbon;

class MalaysiaPayItemClassifier implements ClassifiesPayrollPayItems
{
    public function __construct(private readonly PayItemClassifier $classifier) {}

    public function classificationsFor(PayrollPayItem $payItem, Carbon|string $onDate): array
    {
        return $this->classifier->classificationsFor($payItem, MalaysiaPayrollCountryPack::COUNTRY_ISO, $onDate);
    }
}
