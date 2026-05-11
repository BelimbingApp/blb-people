<?php

namespace App\Modules\People\Payroll\Livewire;

use App\Modules\Core\Employee\Models\Employee;
use App\Modules\People\Payroll\Models\PayrollEmployeeStatutoryProfile;
use App\Modules\People\Payroll\Models\PayrollEmployerStatutoryProfile;
use App\Modules\People\Payroll\Models\PayrollPayItem;
use App\Modules\People\Payroll\Models\PayrollRun;
use App\Modules\People\Payroll\Models\PayrollStatutoryRuleSet;
use App\Modules\People\Payroll\Services\PayrollCountryPackRegistry;
use App\Modules\People\Payroll\Services\PayrollPayslipBuilder;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

final class PayrollIndexWorkbenchData
{
    /**
     * @return Builder<PayrollRun>
     */
    public static function runQuery(int $companyId, string $search): Builder
    {
        return PayrollRun::query()
            ->where('company_id', $companyId)
            ->when($search !== '', function (Builder $query) use ($search): void {
                $query->where(function (Builder $q) use ($search): void {
                    $q->where('code', 'like', '%'.$search.'%')
                        ->orWhere('name', 'like', '%'.$search.'%')
                        ->orWhere('status', 'like', '%'.$search.'%');
                });
            });
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function payslips(?PayrollRun $run): array
    {
        if ($run === null) {
            return [];
        }

        $builder = app(PayrollPayslipBuilder::class);

        return $run->participants
            ->map(fn ($participant): array => $builder->build($participant))
            ->all();
    }

    /**
     * @return Collection<int, PayrollPayItem>
     */
    public static function payItems(int $companyId): Collection
    {
        return PayrollPayItem::query()
            ->with('classifications')
            ->where(function (Builder $query) use ($companyId): void {
                $query->where('company_id', $companyId)
                    ->orWhereNull('company_id');
            })
            ->orderBy('code')
            ->get();
    }

    /**
     * @return Collection<int, PayrollEmployerStatutoryProfile>
     */
    public static function employerProfiles(int $companyId): Collection
    {
        return PayrollEmployerStatutoryProfile::query()
            ->where('company_id', $companyId)
            ->latest('effective_from')
            ->get();
    }

    /**
     * @return Collection<int, PayrollEmployeeStatutoryProfile>
     */
    public static function employeeProfiles(int $companyId): Collection
    {
        return PayrollEmployeeStatutoryProfile::query()
            ->with('employee')
            ->where('company_id', $companyId)
            ->latest('effective_from')
            ->limit(25)
            ->get();
    }

    /**
     * @return Collection<int, PayrollStatutoryRuleSet>
     */
    public static function ruleSets(): Collection
    {
        return PayrollStatutoryRuleSet::query()
            ->with('rows')
            ->orderBy('country_iso')
            ->orderBy('rule_key')
            ->latest('effective_from')
            ->get();
    }

    /**
     * @return Collection<int, Employee>
     */
    public static function employees(int $companyId): Collection
    {
        return Employee::query()
            ->where('company_id', $companyId)
            ->where('status', 'active')
            ->orderBy('full_name')
            ->get();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function countryPacks(): array
    {
        return collect(app(PayrollCountryPackRegistry::class)->all())
            ->map(function ($pack): array {
                $manifest = $pack->manifest();

                return [
                    'country_iso' => $manifest->normalizedCountryIso(),
                    'pack_identifier' => $manifest->packIdentifier,
                    'pack_version' => $manifest->packVersion,
                    'statutory_data_versions' => $manifest->statutoryDataVersions,
                    'employer_schema' => $pack->profileSchemas()->employerSchema(),
                    'employee_schema' => $pack->profileSchemas()->employeeSchema(),
                    'exports' => $pack->exports()->definitions(),
                ];
            })
            ->values()
            ->all();
    }
}
