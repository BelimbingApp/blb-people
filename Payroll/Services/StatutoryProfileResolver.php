<?php
namespace App\Modules\People\Payroll\Services;

use App\Modules\Core\Company\Models\Company;
use App\Modules\Core\Employee\Models\Employee;
use App\Modules\People\Payroll\Models\PayrollEmployeeStatutoryProfile;
use App\Modules\People\Payroll\Models\PayrollEmployerStatutoryProfile;
use Illuminate\Support\Carbon;

class StatutoryProfileResolver
{
    public function employerProfile(Company|int $company, string $countryIso, Carbon|string $onDate): ?PayrollEmployerStatutoryProfile
    {
        $companyId = $company instanceof Company ? $company->id : $company;

        return PayrollEmployerStatutoryProfile::query()
            ->where('company_id', $companyId)
            ->where('country_iso', strtoupper($countryIso))
            ->where('effective_from', '<=', $this->dateString($onDate))
            ->where(function ($query) use ($onDate): void {
                $date = $this->dateString($onDate);
                $query->whereNull('effective_to')
                    ->orWhere('effective_to', '>=', $date);
            })
            ->orderByDesc('effective_from')
            ->first();
    }

    public function employeeProfile(Employee|int $employee, string $countryIso, Carbon|string $onDate): ?PayrollEmployeeStatutoryProfile
    {
        $employeeId = $employee instanceof Employee ? $employee->id : $employee;

        return PayrollEmployeeStatutoryProfile::query()
            ->where('employee_id', $employeeId)
            ->where('country_iso', strtoupper($countryIso))
            ->where('effective_from', '<=', $this->dateString($onDate))
            ->where(function ($query) use ($onDate): void {
                $date = $this->dateString($onDate);
                $query->whereNull('effective_to')
                    ->orWhere('effective_to', '>=', $date);
            })
            ->orderByDesc('effective_from')
            ->first();
    }

    private function dateString(Carbon|string $date): string
    {
        return $date instanceof Carbon ? $date->toDateString() : $date;
    }
}
