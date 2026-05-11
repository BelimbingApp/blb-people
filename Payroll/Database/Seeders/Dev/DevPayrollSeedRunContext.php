<?php

namespace App\Modules\People\Payroll\Database\Seeders\Dev;

use App\Modules\Core\Company\Models\Company;
use App\Modules\People\Payroll\Models\PayrollCalendar;
use App\Modules\People\Payroll\Models\PayrollPayItem;
use App\Modules\People\Payroll\Models\PayrollPeriod;
use Illuminate\Support\Collection;

final readonly class DevPayrollSeedRunContext
{
    /**
     * @param  array<string, PayrollPayItem>  $payItems
     */
    public function __construct(
        public Company $company,
        public PayrollCalendar $calendar,
        public PayrollPeriod $period,
        public string $code,
        public string $name,
        public Collection $employees,
        public array $payItems,
        public bool $close,
    ) {}
}
