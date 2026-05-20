<?php

namespace App\Modules\People\Employees\Services;

use App\Modules\Core\Employee\Models\Employee;
use App\Modules\People\Settings\Models\PeopleReferenceEntry;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class EmployeeWorkbenchQuery
{
    private const NO_ROWS_CONDITION = '1 = 0';

    private const WORK_PROFILE_TABLE = 'people_employee_work_profiles';

    private const REFERENCE_ENTRY_TABLE = 'people_reference_entries';

    private const PORTAL_ACCESS_TABLE = 'people_employee_portal_accesses';

    /**
     * @param  list<int>  $companyIds
     */
    public function build(array $companyIds): Builder
    {
        $hasWorkProfiles = $this->workProfileTableExists();
        $hasReferenceEntries = $hasWorkProfiles && $this->referenceEntryTableExists();
        $hasPortalAccesses = $this->portalAccessTableExists();

        $query = Employee::query()
            ->select([
                'employees.*',
                'companies.name as company_name',
                'employee_types.label as employee_type_label',
                ...$this->workProfileSelectColumns($hasWorkProfiles),
                ...$this->referenceSelectColumns($hasReferenceEntries),
                ...$this->portalAccessSelectColumns($hasPortalAccesses),
            ])
            ->leftJoin('companies', 'employees.company_id', '=', 'companies.id')
            ->leftJoin('employee_types', 'employees.employee_type', '=', 'employee_types.code')
            ->whereIn('employees.company_id', $companyIds)
            ->with($this->withRelations($hasWorkProfiles, $hasReferenceEntries, $hasPortalAccesses));

        if ($hasWorkProfiles) {
            $query->leftJoin(self::WORK_PROFILE_TABLE, self::WORK_PROFILE_TABLE.'.employee_id', '=', 'employees.id');
        }

        if ($hasReferenceEntries) {
            $query
                ->leftJoin(self::REFERENCE_ENTRY_TABLE.' as cost_centers', self::WORK_PROFILE_TABLE.'.cost_center_id', '=', 'cost_centers.id')
                ->leftJoin(self::REFERENCE_ENTRY_TABLE.' as organization_units', self::WORK_PROFILE_TABLE.'.organization_unit_id', '=', 'organization_units.id')
                ->leftJoin(self::REFERENCE_ENTRY_TABLE.' as employment_groups', self::WORK_PROFILE_TABLE.'.employment_group_id', '=', 'employment_groups.id')
                ->leftJoin(self::REFERENCE_ENTRY_TABLE.' as job_titles', self::WORK_PROFILE_TABLE.'.job_title_id', '=', 'job_titles.id')
                ->leftJoin(self::REFERENCE_ENTRY_TABLE.' as workforce_classes', self::WORK_PROFILE_TABLE.'.workforce_class_id', '=', 'workforce_classes.id')
                ->leftJoin(self::REFERENCE_ENTRY_TABLE.' as job_grades', self::WORK_PROFILE_TABLE.'.job_grade_id', '=', 'job_grades.id')
                ->leftJoin(self::REFERENCE_ENTRY_TABLE.' as work_calendars', self::WORK_PROFILE_TABLE.'.work_calendar_id', '=', 'work_calendars.id');
        }

        if ($hasPortalAccesses) {
            $query->leftJoin(self::PORTAL_ACCESS_TABLE, self::PORTAL_ACCESS_TABLE.'.employee_id', '=', 'employees.id');
        }

        return $query;
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    public function applyFilters(Builder $query, array $filters, EmployeePayrollReadinessService $readiness): void
    {
        $hasWorkProfiles = $this->workProfileTableExists();
        $hasReferenceEntries = $hasWorkProfiles && $this->referenceEntryTableExists();
        $hasPortalAccesses = $this->portalAccessTableExists();
        $search = trim((string) ($filters['search'] ?? ''));

        if ($search !== '') {
            $query->where(function (Builder $searchQuery) use ($search, $hasReferenceEntries): void {
                $like = '%'.$search.'%';

                $searchQuery->where('employees.full_name', 'like', $like)
                    ->orWhere('employees.short_name', 'like', $like)
                    ->orWhere('employees.employee_number', 'like', $like)
                    ->orWhere('employees.email', 'like', $like)
                    ->orWhere('employees.designation', 'like', $like)
                    ->orWhere('companies.name', 'like', $like);

                if (! $hasReferenceEntries) {
                    return;
                }

                $searchQuery
                    ->orWhere('cost_centers.name', 'like', $like)
                    ->orWhere('organization_units.name', 'like', $like)
                    ->orWhere('employment_groups.name', 'like', $like)
                    ->orWhere('job_titles.name', 'like', $like)
                    ->orWhere('workforce_classes.name', 'like', $like)
                    ->orWhere('job_grades.name', 'like', $like)
                    ->orWhere('work_calendars.name', 'like', $like);
            });
        }

        $this->applyIntegerFilter($query, 'employees.company_id', $filters['company_id'] ?? null);
        $this->applyStringFilter($query, 'employees.status', $filters['status'] ?? null);
        $this->applyWorkProfileFilter($query, $hasReferenceEntries, self::WORK_PROFILE_TABLE.'.organization_unit_id', $filters['organization_unit_id'] ?? null);
        $this->applyWorkProfileFilter($query, $hasReferenceEntries, self::WORK_PROFILE_TABLE.'.cost_center_id', $filters['cost_center_id'] ?? null);
        $this->applyWorkProfileFilter($query, $hasReferenceEntries, self::WORK_PROFILE_TABLE.'.employment_group_id', $filters['employment_group_id'] ?? null);
        $this->applyWorkProfileFilter($query, $hasReferenceEntries, self::WORK_PROFILE_TABLE.'.job_title_id', $filters['job_title_id'] ?? null);
        $this->applyWorkProfileFilter($query, $hasReferenceEntries, self::WORK_PROFILE_TABLE.'.workforce_class_id', $filters['workforce_class_id'] ?? null);
        $this->applyWorkProfileFilter($query, $hasReferenceEntries, self::WORK_PROFILE_TABLE.'.job_grade_id', $filters['job_grade_id'] ?? null);
        $this->applyWorkProfileFilter($query, $hasReferenceEntries, self::WORK_PROFILE_TABLE.'.work_calendar_id', $filters['work_calendar_id'] ?? null);

        if ($hasWorkProfiles) {
            $this->applyStringFilter($query, self::WORK_PROFILE_TABLE.'.pay_rate_type', $filters['pay_rate_type'] ?? null);
        } elseif (trim((string) ($filters['pay_rate_type'] ?? '')) !== '') {
            $query->whereRaw(self::NO_ROWS_CONDITION);
        }

        $portalAccessStatus = (string) ($filters['portal_access_status'] ?? '');

        if (! $hasPortalAccesses && $portalAccessStatus === 'unprovisioned') {
            // Missing portal-access table means every employee is effectively unprovisioned.
        } elseif (! $hasPortalAccesses && $portalAccessStatus !== '') {
            $query->whereRaw(self::NO_ROWS_CONDITION);
        } elseif ($portalAccessStatus === 'unprovisioned') {
            $query->whereNull('people_employee_portal_accesses.id');
        } elseif ($portalAccessStatus !== '') {
            $query->where('people_employee_portal_accesses.status', $portalAccessStatus);
        }

        $readinessState = (string) ($filters['readiness_state'] ?? '');
        if ($readinessState !== '') {
            $readiness->applyStateFilter($query, $readinessState);
        }

        $readinessBlocker = (string) ($filters['readiness_blocker'] ?? '');
        if ($readinessBlocker !== '') {
            $readiness->applyBlockerFilter($query, $readinessBlocker);
        }
    }

    /**
     * @param  list<int>  $companyIds
     * @return Collection<int, PeopleReferenceEntry>
     */
    public function referenceOptions(array $companyIds, string $type)
    {
        if (! $this->referenceEntryTableExists()) {
            return collect();
        }

        return PeopleReferenceEntry::query()
            ->whereIn('company_id', $companyIds)
            ->where('type', $type)
            ->orderBy('name')
            ->get();
    }

    /**
     * @return list<string|\Illuminate\Database\Query\Expression>
     */
    private function workProfileSelectColumns(bool $hasWorkProfiles): array
    {
        if ($hasWorkProfiles) {
            return [
                self::WORK_PROFILE_TABLE.'.id as work_profile_id',
                self::WORK_PROFILE_TABLE.'.pay_rate_type as work_profile_pay_basis',
                self::WORK_PROFILE_TABLE.'.hired_on as work_profile_hired_on',
                self::WORK_PROFILE_TABLE.'.resigned_on as work_profile_resigned_on',
            ];
        }

        return [
            DB::raw('null as work_profile_id'),
            DB::raw('null as work_profile_pay_basis'),
            DB::raw('null as work_profile_hired_on'),
            DB::raw('null as work_profile_resigned_on'),
        ];
    }

    /**
     * @return list<string|\Illuminate\Database\Query\Expression>
     */
    private function referenceSelectColumns(bool $hasReferenceEntries): array
    {
        $aliases = [
            'cost_center_name',
            'cost_center_code',
            'cost_center_source_label',
            'cost_center_source_code',
            'organization_unit_name',
            'organization_unit_code',
            'organization_unit_source_label',
            'organization_unit_source_code',
            'employment_group_name',
            'employment_group_code',
            'employment_group_source_label',
            'employment_group_source_code',
            'job_title_name',
            'job_title_code',
            'job_title_source_label',
            'job_title_source_code',
            'workforce_class_name',
            'workforce_class_code',
            'workforce_class_source_label',
            'workforce_class_source_code',
            'job_grade_name',
            'job_grade_code',
            'job_grade_source_label',
            'job_grade_source_code',
            'work_calendar_name',
            'work_calendar_code',
            'work_calendar_source_label',
            'work_calendar_source_code',
        ];

        if (! $hasReferenceEntries) {
            return array_map(static fn (string $alias) => DB::raw('null as '.$alias), $aliases);
        }

        return [
            'cost_centers.name as cost_center_name',
            'cost_centers.code as cost_center_code',
            'cost_centers.source_label as cost_center_source_label',
            'cost_centers.source_code as cost_center_source_code',
            'organization_units.name as organization_unit_name',
            'organization_units.code as organization_unit_code',
            'organization_units.source_label as organization_unit_source_label',
            'organization_units.source_code as organization_unit_source_code',
            'employment_groups.name as employment_group_name',
            'employment_groups.code as employment_group_code',
            'employment_groups.source_label as employment_group_source_label',
            'employment_groups.source_code as employment_group_source_code',
            'job_titles.name as job_title_name',
            'job_titles.code as job_title_code',
            'job_titles.source_label as job_title_source_label',
            'job_titles.source_code as job_title_source_code',
            'workforce_classes.name as workforce_class_name',
            'workforce_classes.code as workforce_class_code',
            'workforce_classes.source_label as workforce_class_source_label',
            'workforce_classes.source_code as workforce_class_source_code',
            'job_grades.name as job_grade_name',
            'job_grades.code as job_grade_code',
            'job_grades.source_label as job_grade_source_label',
            'job_grades.source_code as job_grade_source_code',
            'work_calendars.name as work_calendar_name',
            'work_calendars.code as work_calendar_code',
            'work_calendars.source_label as work_calendar_source_label',
            'work_calendars.source_code as work_calendar_source_code',
        ];
    }

    /**
     * @return list<string|\Illuminate\Database\Query\Expression>
     */
    private function portalAccessSelectColumns(bool $hasPortalAccesses): array
    {
        if ($hasPortalAccesses) {
            return [
                self::PORTAL_ACCESS_TABLE.'.status as portal_access_status',
                self::PORTAL_ACCESS_TABLE.'.login_identifier as portal_access_login_identifier',
                self::PORTAL_ACCESS_TABLE.'.email as portal_access_email',
                self::PORTAL_ACCESS_TABLE.'.last_invited_at as portal_access_last_invited_at',
            ];
        }

        return [
            DB::raw('null as portal_access_status'),
            DB::raw('null as portal_access_login_identifier'),
            DB::raw('null as portal_access_email'),
            DB::raw('null as portal_access_last_invited_at'),
        ];
    }

    /**
     * @return list<string>
     */
    private function withRelations(bool $hasWorkProfiles, bool $hasReferenceEntries, bool $hasPortalAccesses): array
    {
        $relations = [
            'company',
            'department.type',
        ];

        if ($hasPortalAccesses) {
            $relations[] = 'portalAccess.user';
        }

        if (! $hasWorkProfiles) {
            return $relations;
        }

        $relations[] = 'workProfile';

        if ($hasReferenceEntries) {
            $relations[] = 'workProfile.costCenter';
            $relations[] = 'workProfile.organizationUnit';
            $relations[] = 'workProfile.employmentGroup';
            $relations[] = 'workProfile.jobTitle';
            $relations[] = 'workProfile.workforceClass';
            $relations[] = 'workProfile.jobGrade';
            $relations[] = 'workProfile.workCalendar';
        }

        return $relations;
    }

    private function applyWorkProfileFilter(Builder $query, bool $tableExists, string $column, mixed $value): void
    {
        if ($tableExists) {
            $this->applyIntegerFilter($query, $column, $value);

            return;
        }

        if (filter_var($value, FILTER_VALIDATE_INT) !== false) {
            $query->whereRaw(self::NO_ROWS_CONDITION);
        }
    }

    private function workProfileTableExists(): bool
    {
        return Schema::hasTable(self::WORK_PROFILE_TABLE);
    }

    private function referenceEntryTableExists(): bool
    {
        return Schema::hasTable(self::REFERENCE_ENTRY_TABLE);
    }

    private function portalAccessTableExists(): bool
    {
        return Schema::hasTable(self::PORTAL_ACCESS_TABLE);
    }

    private function applyIntegerFilter(Builder $query, string $column, mixed $value): void
    {
        $intValue = filter_var($value, FILTER_VALIDATE_INT);

        if ($intValue === false) {
            return;
        }

        $query->where($column, (int) $intValue);
    }

    private function applyStringFilter(Builder $query, string $column, mixed $value): void
    {
        $stringValue = trim((string) $value);

        if ($stringValue === '') {
            return;
        }

        $query->where($column, $stringValue);
    }
}
