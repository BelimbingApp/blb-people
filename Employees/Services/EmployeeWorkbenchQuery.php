<?php

namespace App\Modules\People\Employees\Services;

use App\Modules\Core\Employee\Models\Employee;
use App\Modules\People\Settings\Models\PeopleReferenceEntry;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class EmployeeWorkbenchQuery
{
    /**
     * @param  list<int>  $companyIds
     */
    public function build(array $companyIds): Builder
    {
        return Employee::query()
            ->select([
                'employees.*',
                'companies.name as company_name',
                'employee_types.label as employee_type_label',
                'employee_work_profiles.id as work_profile_id',
                'employee_work_profiles.pay_rate_type as work_profile_pay_basis',
                'employee_work_profiles.hired_on as work_profile_hired_on',
                'employee_work_profiles.resigned_on as work_profile_resigned_on',
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
                'employee_portal_accesses.status as portal_access_status',
                'employee_portal_accesses.login_identifier as portal_access_login_identifier',
                'employee_portal_accesses.email as portal_access_email',
                'employee_portal_accesses.last_invited_at as portal_access_last_invited_at',
            ])
            ->leftJoin('companies', 'employees.company_id', '=', 'companies.id')
            ->leftJoin('employee_types', 'employees.employee_type', '=', 'employee_types.code')
            ->leftJoin('employee_work_profiles', 'employee_work_profiles.employee_id', '=', 'employees.id')
            ->leftJoin('people_reference_entries as cost_centers', 'employee_work_profiles.cost_center_id', '=', 'cost_centers.id')
            ->leftJoin('people_reference_entries as organization_units', 'employee_work_profiles.organization_unit_id', '=', 'organization_units.id')
            ->leftJoin('people_reference_entries as employment_groups', 'employee_work_profiles.employment_group_id', '=', 'employment_groups.id')
            ->leftJoin('people_reference_entries as job_titles', 'employee_work_profiles.job_title_id', '=', 'job_titles.id')
            ->leftJoin('people_reference_entries as workforce_classes', 'employee_work_profiles.workforce_class_id', '=', 'workforce_classes.id')
            ->leftJoin('people_reference_entries as job_grades', 'employee_work_profiles.job_grade_id', '=', 'job_grades.id')
            ->leftJoin('people_reference_entries as work_calendars', 'employee_work_profiles.work_calendar_id', '=', 'work_calendars.id')
            ->leftJoin('employee_portal_accesses', 'employee_portal_accesses.employee_id', '=', 'employees.id')
            ->whereIn('employees.company_id', $companyIds)
            ->with([
                'company',
                'department.type',
                'portalAccess.user',
                'workProfile.costCenter',
                'workProfile.organizationUnit',
                'workProfile.employmentGroup',
                'workProfile.jobTitle',
                'workProfile.workforceClass',
                'workProfile.jobGrade',
                'workProfile.workCalendar',
                'statutoryProfiles',
            ]);
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    public function applyFilters(Builder $query, array $filters, EmployeePayrollReadinessService $readiness): void
    {
        $search = trim((string) ($filters['search'] ?? ''));

        if ($search !== '') {
            $query->where(function (Builder $searchQuery) use ($search): void {
                $like = '%'.$search.'%';

                $searchQuery->where('employees.full_name', 'like', $like)
                    ->orWhere('employees.short_name', 'like', $like)
                    ->orWhere('employees.employee_number', 'like', $like)
                    ->orWhere('employees.email', 'like', $like)
                    ->orWhere('employees.designation', 'like', $like)
                    ->orWhere('companies.name', 'like', $like)
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
        $this->applyIntegerFilter($query, 'employee_work_profiles.organization_unit_id', $filters['organization_unit_id'] ?? null);
        $this->applyIntegerFilter($query, 'employee_work_profiles.cost_center_id', $filters['cost_center_id'] ?? null);
        $this->applyIntegerFilter($query, 'employee_work_profiles.employment_group_id', $filters['employment_group_id'] ?? null);
        $this->applyIntegerFilter($query, 'employee_work_profiles.job_title_id', $filters['job_title_id'] ?? null);
        $this->applyIntegerFilter($query, 'employee_work_profiles.workforce_class_id', $filters['workforce_class_id'] ?? null);
        $this->applyIntegerFilter($query, 'employee_work_profiles.job_grade_id', $filters['job_grade_id'] ?? null);
        $this->applyIntegerFilter($query, 'employee_work_profiles.work_calendar_id', $filters['work_calendar_id'] ?? null);
        $this->applyStringFilter($query, 'employee_work_profiles.pay_rate_type', $filters['pay_rate_type'] ?? null);

        $portalAccessStatus = (string) ($filters['portal_access_status'] ?? '');

        if ($portalAccessStatus === 'unprovisioned') {
            $query->whereNull('employee_portal_accesses.id');
        } elseif ($portalAccessStatus !== '') {
            $query->where('employee_portal_accesses.status', $portalAccessStatus);
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
        return PeopleReferenceEntry::query()
            ->whereIn('company_id', $companyIds)
            ->where('type', $type)
            ->orderBy('name')
            ->get();
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
