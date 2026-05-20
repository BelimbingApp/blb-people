<?php

namespace App\Modules\People\Attendance\Livewire\Concerns;

use App\Modules\Core\Company\Models\Department;
use App\Modules\Core\Employee\Models\Employee;
use App\Modules\People\Attendance\Models\AttendanceDay;
use App\Modules\People\Attendance\Models\AttendancePolicyGroup;
use App\Modules\People\Attendance\Models\AttendanceRosterAssignment;
use App\Modules\People\Attendance\Models\AttendanceRosterPattern;
use App\Modules\People\Attendance\Models\AttendanceShiftTemplate;
use App\Modules\People\Settings\Models\PeopleReferenceEntry;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

trait BuildsRosterRenderingData
{
    /**
     * @return array<string, mixed>
     */
    private function renderDataForReadySchema(int $companyId): array
    {
        $employees = $this->filteredEmployeesQuery()
            ->orderBy('full_name')
            ->orderBy('id')
            ->paginate(25);

        $rosterGridDays = $this->rosterGridDays();
        $rosterGridRows = $this->rosterGridRows($employees->getCollection());
        $rosterGridDays = $this->enrichGridDays($rosterGridDays, $rosterGridRows);
        $rosterCoverageRows = $this->rosterCoverageRows();

        return [
            'employees' => $employees,
            'editingEmployee' => $this->resolveEditingEmployee($companyId),
            'filteredEmployeeCount' => $this->filteredEmployeesQuery()->count(),
            'companyEmployeeCount' => Employee::query()->where('company_id', $companyId)->count(),
            'selectedEmployeeCount' => count($this->selectedRosterEmployeeIds()),
            'rosterGridDays' => $rosterGridDays,
            'rosterGridRows' => $rosterGridRows,
            'rosterListSummary' => $this->rosterListSummary($rosterGridRows, $rosterGridDays),
            'rosterCoverageRows' => $rosterCoverageRows,
            'rosterCoverageMatrix' => $this->rosterCoverageMatrix($rosterCoverageRows),
            'rosterValidationFindings' => $this->rosterValidationFindings(),
            'rosterTemplates' => $this->rosterTemplates(),
            'spreadsheetPreviewRows' => $this->parseSpreadsheetRows()['rows'],
            'departments' => Department::query()
                ->select('company_departments.*')
                ->where('company_departments.company_id', $companyId)
                ->leftJoin('company_department_types', 'company_departments.department_type_id', '=', 'company_department_types.id')
                ->with('type')
                ->orderBy('company_department_types.name')
                ->get(),
            'supervisors' => Employee::query()
                ->where('company_id', $companyId)
                ->whereNotNull('id')
                ->orderBy('full_name')
                ->get(['id', 'full_name', 'employee_number']),
            'shiftTemplates' => AttendanceShiftTemplate::query()
                ->where('company_id', $companyId)
                ->orderBy('code')
                ->get(),
            'policyGroups' => AttendancePolicyGroup::query()
                ->where('company_id', $companyId)
                ->orderBy('code')
                ->get(),
            'rosterPatterns' => AttendanceRosterPattern::query()
                ->where('company_id', $companyId)
                ->orderBy('code')
                ->get(),
            'rosterAssignments' => AttendanceRosterAssignment::query()
                ->where('company_id', $companyId)
                ->with(['employee', 'shiftTemplate', 'policyGroup', 'rosterPattern'])
                ->latest('effective_from')
                ->limit(40)
                ->get(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function renderDataForUnreadySchema(): array
    {
        return [
            'employees' => collect(),
            'editingEmployee' => null,
            'filteredEmployeeCount' => 0,
            'companyEmployeeCount' => 0,
            'selectedEmployeeCount' => 0,
            'rosterGridDays' => [],
            'rosterGridRows' => collect(),
            'rosterListSummary' => null,
            'rosterCoverageRows' => [],
            'rosterCoverageMatrix' => ['shifts' => [], 'dates' => [], 'cells' => []],
            'rosterValidationFindings' => [],
            'rosterTemplates' => collect(),
            'spreadsheetPreviewRows' => [],
            'departments' => collect(),
            'supervisors' => collect(),
            'shiftTemplates' => collect(),
            'policyGroups' => collect(),
            'rosterPatterns' => collect(),
            'rosterAssignments' => collect(),
        ];
    }

    private function resolveEditingEmployee(int $companyId): ?Employee
    {
        if ($this->editingRosterAssignmentId === '' || $this->rosterEmployeeId === '') {
            return null;
        }

        return Employee::query()
            ->where('company_id', $companyId)
            ->whereKey((int) $this->rosterEmployeeId)
            ->first();
    }

    /**
     * @param  Collection<int, PeopleReferenceEntry>  $workforceClasses
     * @param  Collection<int, Department>  $departments
     * @return array{
     *     hasActiveFilters: bool,
     *     departmentLabel: string,
     *     workforceClassLabel: string,
     *     statusLabel: string,
     * }
     */
    public function rosterListFilterContext(Collection $departments, Collection $workforceClasses): array
    {
        $departmentLabel = __('all departments');
        if ($this->rosterDepartmentId !== '') {
            $match = $departments->firstWhere('id', (int) $this->rosterDepartmentId);
            if ($match !== null) {
                $departmentLabel = (string) $match->name;
            }
        }

        $workforceClassLabel = __('all workforce classes');
        if ($this->rosterWorkforceClassId !== '') {
            $match = $workforceClasses->firstWhere('id', (int) $this->rosterWorkforceClassId);
            if ($match !== null) {
                $workforceClassLabel = (string) $match->name;
            }
        }

        $statusLabel = match ($this->rosterEmployeeStatus) {
            'active' => __('active'),
            'probation' => __('probation'),
            'pending' => __('pending'),
            'inactive' => __('inactive'),
            'terminated' => __('terminated'),
            default => __('any status'),
        };

        $hasActiveFilters = $this->rosterDepartmentId !== ''
            || $this->rosterWorkforceClassId !== ''
            || $this->rosterEmployeeStatus !== 'active'
            || $this->rosterSearch !== ''
            || $this->rosterSupervisorId !== ''
            || $this->rosterOrganizationUnitId !== ''
            || $this->rosterCostCenterId !== ''
            || $this->rosterEmploymentGroupId !== ''
            || $this->rosterWorkCalendarId !== ''
            || $this->rosterPayRateType !== '';

        return [
            'hasActiveFilters' => $hasActiveFilters,
            'departmentLabel' => $departmentLabel,
            'workforceClassLabel' => $workforceClassLabel,
            'statusLabel' => $statusLabel,
        ];
    }

    /**
     * Build a short narrative for the calendar in list mode that names how many
     * gaps and exceptions live in the visible window. Counts come from the
     * already-computed grid rows, so this adds no DB queries.
     *
     * @param  Collection<int, array<string, mixed>>  $rows
     * @param  list<array{date: string, day: string, label: string}>  $days
     * @return array{gaps: int, exceptions: int, sentence: string, hasIssues: bool}
     */
    private function rosterListSummary(Collection $rows, array $days): array
    {
        ['gaps' => $gaps, 'exceptions' => $exceptions] = $this->countRosterIssues($rows, $days);
        $hasIssues = $gaps > 0 || $exceptions > 0;
        $periodEnd = $days === [] ? null : end($days)['label'] ?? null;

        return [
            'gaps' => $gaps,
            'exceptions' => $exceptions,
            'sentence' => $this->rosterSummarySentence($gaps, $exceptions, $periodEnd),
            'hasIssues' => $hasIssues,
        ];
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @param  list<array{date: string, day: string, label: string}>  $days
     * @return array{gaps: int, exceptions: int}
     */
    private function countRosterIssues(Collection $rows, array $days): array
    {
        $gaps = 0;
        $exceptions = 0;

        foreach ($rows as $row) {
            foreach ($days as $day) {
                $cell = $row['cells'][$day['date']] ?? null;
                if (! is_array($cell)) {
                    continue;
                }

                $isWorking = ($cell['day_type'] ?? AttendanceDay::DAY_TYPE_NORMAL) === AttendanceDay::DAY_TYPE_NORMAL;
                $isEmpty = ($cell['state'] ?? 'empty') === 'empty';

                if ($isWorking && $isEmpty) {
                    $gaps++;
                } elseif (! $isWorking && ! $isEmpty) {
                    $exceptions++;
                }
            }
        }

        return ['gaps' => $gaps, 'exceptions' => $exceptions];
    }

    private function rosterSummarySentence(int $gaps, int $exceptions, ?string $periodEnd): string
    {
        if ($gaps === 0 && $exceptions === 0) {
            return $periodEnd
                ? __('All set through :date.', ['date' => $periodEnd])
                : __('All set.');
        }

        $parts = [];
        if ($gaps > 0) {
            $parts[] = trans_choice(':count gap|:count gaps', $gaps, ['count' => $gaps]);
        }
        if ($exceptions > 0) {
            $parts[] = trans_choice(':count exception|:count exceptions', $exceptions, ['count' => $exceptions]);
        }
        $listLabel = ucfirst(implode(' '.__('and').' ', $parts));

        return $periodEnd
            ? __(':items to sort out before :date.', ['items' => $listLabel, 'date' => $periodEnd])
            : __(':items to sort out.', ['items' => $listLabel]);
    }

    private function referenceOptions(string $type, bool $schemaReady)
    {
        if (! $schemaReady) {
            return collect();
        }

        return PeopleReferenceEntry::query()
            ->where('company_id', $this->companyId())
            ->where('type', $type)
            ->orderBy('name')
            ->get();
    }

    /**
     * @return array<string, string>
     */
    private function rosterFilters(): array
    {
        return [
            'search' => $this->rosterSearch,
            'department_id' => $this->rosterDepartmentId,
            'supervisor_id' => $this->rosterSupervisorId,
            'organization_unit_id' => $this->rosterOrganizationUnitId,
            'cost_center_id' => $this->rosterCostCenterId,
            'workforce_class_id' => $this->rosterWorkforceClassId,
            'employment_group_id' => $this->rosterEmploymentGroupId,
            'work_calendar_id' => $this->rosterWorkCalendarId,
            'pay_rate_type' => $this->rosterPayRateType,
            'status' => $this->rosterEmployeeStatus,
        ];
    }

    /**
     * @return list<string>
     */
    private function rosterFilterProperties(): array
    {
        return [
            'rosterSearch',
            'rosterDepartmentId',
            'rosterSupervisorId',
            'rosterOrganizationUnitId',
            'rosterCostCenterId',
            'rosterWorkforceClassId',
            'rosterEmploymentGroupId',
            'rosterWorkCalendarId',
            'rosterPayRateType',
            'rosterEmployeeStatus',
        ];
    }

    private function applyIntegerFilter(Builder $query, string $column, mixed $value): void
    {
        if (filter_var($value, FILTER_VALIDATE_INT) === false) {
            return;
        }

        $query->where($column, (int) $value);
    }

    /**
     * @return list<int>
     */
    private function selectedRosterEmployeeIds(): array
    {
        if ($this->rosterSelectAllFiltered) {
            return $this->filteredEmployeesQuery()
                ->orderBy('employees.id')
                ->limit(500)
                ->pluck('employees.id')
                ->map(fn (int $id): int => $id)
                ->all();
        }

        $ids = array_filter($this->selectedRosterEmployeeIds, fn (mixed $id): bool => filter_var($id, FILTER_VALIDATE_INT) !== false);

        if ($ids === [] && filter_var($this->rosterEmployeeId, FILTER_VALIDATE_INT) !== false) {
            $ids = [$this->rosterEmployeeId];
        }

        $companyId = $this->companyId();

        return Employee::query()
            ->where('company_id', $companyId)
            ->whereIn('id', array_map('intval', $ids))
            ->pluck('id')
            ->map(fn (int $id): int => $id)
            ->all();
    }

    /**
     * @return Builder<Employee>
     */
    private function filteredEmployeesQuery(): Builder
    {
        $query = Employee::query()
            ->select('employees.*')
            ->leftJoin('people_employee_work_profiles', 'people_employee_work_profiles.employee_id', '=', 'employees.id')
            ->where('employees.company_id', $this->companyId());

        if ($this->isMyScheduleMode()) {
            $employeeId = $this->currentEmployeeId();
            if ($employeeId !== null) {
                $query->where('employees.id', $employeeId);
            } else {
                $query->whereRaw('1 = 0');
            }

            return $query->with(['department.type', 'workProfile.organizationUnit', 'workProfile.costCenter', 'workProfile.workforceClass']);
        }

        $search = trim($this->rosterSearch);
        if ($search !== '') {
            $query->where(function (Builder $searchQuery) use ($search): void {
                $like = '%'.$search.'%';

                $searchQuery->where('employees.full_name', 'like', $like)
                    ->orWhere('employees.short_name', 'like', $like)
                    ->orWhere('employees.employee_number', 'like', $like)
                    ->orWhere('employees.designation', 'like', $like);
            });
        }

        $this->applyIntegerFilter($query, 'employees.department_id', $this->rosterDepartmentId);
        $this->applyIntegerFilter($query, 'employees.supervisor_id', $this->rosterSupervisorId);
        $this->applyIntegerFilter($query, 'people_employee_work_profiles.organization_unit_id', $this->rosterOrganizationUnitId);
        $this->applyIntegerFilter($query, 'people_employee_work_profiles.cost_center_id', $this->rosterCostCenterId);
        $this->applyIntegerFilter($query, 'people_employee_work_profiles.workforce_class_id', $this->rosterWorkforceClassId);
        $this->applyIntegerFilter($query, 'people_employee_work_profiles.employment_group_id', $this->rosterEmploymentGroupId);
        $this->applyIntegerFilter($query, 'people_employee_work_profiles.work_calendar_id', $this->rosterWorkCalendarId);

        if ($this->rosterPayRateType !== '') {
            $query->where('people_employee_work_profiles.pay_rate_type', $this->rosterPayRateType);
        }

        if ($this->rosterEmployeeStatus !== '') {
            $query->where('employees.status', $this->rosterEmployeeStatus);
        }

        return $query->with(['department.type', 'workProfile.organizationUnit', 'workProfile.costCenter', 'workProfile.workforceClass']);
    }

    private function resetForm(): void
    {
        $this->editingRosterAssignmentId = '';
        $this->rosterEmployeeId = '';
        $this->selectedRosterEmployeeIds = [];
        $this->rosterSelectAllFiltered = false;
        $this->rosterPatternId = '';
        $this->rosterShiftTemplateId = '';
        $this->rosterPolicyGroupId = '';
        $this->rosterEffectiveFrom = now()->toDateString();
        $this->rosterEffectiveTo = '';
        $this->rosterPublishState = 'draft';
    }

    private function hasRosterOverlap(int $employeeId, string $effectiveFrom, ?string $effectiveTo, ?int $excludeAssignmentId = null): bool
    {
        $rangeEnd = $effectiveTo ?? '9999-12-31';

        return AttendanceRosterAssignment::query()
            ->where('company_id', $this->companyId())
            ->where('employee_id', $employeeId)
            ->where('effective_from', '<=', $rangeEnd)
            ->where(function ($query) use ($effectiveFrom): void {
                $query->whereNull('effective_to')
                    ->orWhere('effective_to', '>=', $effectiveFrom);
            })
            ->when($excludeAssignmentId !== null, fn ($query) => $query->where('id', '!=', $excludeAssignmentId))
            ->exists();
    }
}
