<?php

namespace App\Modules\People\Payroll\Contracts;

use App\Modules\People\Payroll\Models\PayrollPayItem;
use Illuminate\Support\Carbon;

interface ClassifiesPayrollPayItems
{
    /**
     * @return array<string, array<string, mixed>>
     */
    public function classificationsFor(PayrollPayItem $payItem, Carbon|string $onDate): array;
}
