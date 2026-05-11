<?php

namespace App\Modules\People\Payroll\CountryPacks\Malaysia;

use App\Modules\People\Payroll\Contracts\ProvidesPayrollExports;
use App\Modules\People\Payroll\Data\PayrollExportDefinition;

class MalaysiaPayrollExports implements ProvidesPayrollExports
{
    public function definitions(): array
    {
        return [
            new PayrollExportDefinition(
                key: 'epf_monthly_contribution',
                label: 'EPF monthly contribution',
                frequency: 'monthly',
                format: 'csv',
                metadata: ['status' => 'planned'],
            ),
            new PayrollExportDefinition(
                key: 'socso_eis_monthly_contribution',
                label: 'SOCSO/EIS monthly contribution',
                frequency: 'monthly',
                format: 'csv',
                metadata: ['status' => 'planned'],
            ),
            new PayrollExportDefinition(
                key: 'pcb_cp39_monthly_submission',
                label: 'PCB/CP39 monthly submission',
                frequency: 'monthly',
                format: 'csv',
                metadata: ['status' => 'planned'],
            ),
        ];
    }
}
