<?php

namespace App\Modules\People\Employees\Livewire;

use App\Base\Foundation\Livewire\Concerns\ResetsPaginationOnSearch;
use App\Base\Foundation\Livewire\Concerns\TogglesSort;
use App\Modules\Core\Company\Models\Company;
use App\Modules\People\Employees\Services\EmployeePayrollReadinessService;
use App\Modules\People\Employees\Services\EmployeeWorkbenchQuery;
use App\Modules\People\Settings\Models\PeopleReferenceEntry;
use App\Modules\People\Settings\Models\PeopleSavedEmployeeView;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;
use Livewire\Component;
use Livewire\WithPagination;

class Index extends Component
{
    use ResetsPaginationOnSearch;
    use TogglesSort;
    use WithPagination;

    public string $search = '';

    public string $status = '';

    public string $companyId = '';

    public string $organizationUnitId = '';

    public string $costCenterId = '';

    public string $employmentGroupId = '';

    public string $jobTitleId = '';

    public string $workforceClassId = '';

    public string $jobGradeId = '';

    public string $workCalendarId = '';

    public string $payRateType = '';

    public string $portalAccessStatus = '';

    public string $readinessState = '';

    public string $readinessBlocker = '';

    public string $savedViewName = '';

    public string $savedViewVisibility = 'private';

    public string $sortBy = 'full_name';

    public string $sortDir = 'asc';

    private const SORTABLE = [
        'full_name' => 'employees.full_name',
        'company_name' => 'company_name',
        'employee_type_label' => 'employee_type_label',
        'status' => 'employees.status',
        'organization_unit_name' => 'organization_unit_name',
        'cost_center_name' => 'cost_center_name',
        'job_title_name' => 'job_title_name',
        'work_profile_pay_basis' => 'work_profile_pay_basis',
        'portal_access_status' => 'portal_access_status',
    ];

    public function updated(string $property): void
    {
        if (in_array($property, $this->filterProperties(), true)) {
            $this->resetPage();
        }
    }

    public function sort(string $column): void
    {
        $this->toggleSort(
            column: $column,
            allowedColumns: self::SORTABLE,
            defaultDir: [
                'full_name' => 'asc',
                'company_name' => 'asc',
                'employee_type_label' => 'asc',
                'status' => 'asc',
                'organization_unit_name' => 'asc',
                'cost_center_name' => 'asc',
                'job_title_name' => 'asc',
                'work_profile_pay_basis' => 'asc',
                'portal_access_status' => 'asc',
            ],
        );
    }

    public function clearFilters(): void
    {
        $this->reset([
            'search',
            'status',
            'companyId',
            'organizationUnitId',
            'costCenterId',
            'employmentGroupId',
            'jobTitleId',
            'workforceClassId',
            'jobGradeId',
            'workCalendarId',
            'payRateType',
            'portalAccessStatus',
            'readinessState',
            'readinessBlocker',
        ]);

        $this->resetPage();
    }

    public function saveCurrentView(): void
    {
        if (! $this->savedEmployeeViewsTableExists()) {
            session()->flash('error', __('Saved employee views are unavailable until People settings tables are rebuilt.'));

            return;
        }

        $validated = $this->validate([
            'savedViewName' => ['required', 'string', 'max:255'],
            'savedViewVisibility' => ['required', 'in:private,company'],
        ]);

        PeopleSavedEmployeeView::query()->updateOrCreate(
            [
                'company_id' => $this->currentCompanyId(),
                'user_id' => Auth::id(),
                'name' => $validated['savedViewName'],
            ],
            [
                'visibility' => $validated['savedViewVisibility'],
                'status' => 'active',
                'filters' => $this->filters(),
                'sort' => [
                    'by' => $this->sortBy,
                    'dir' => $this->sortDir,
                ],
                'metadata' => [
                    'surface' => 'employee_workbench',
                    'scope_company_id' => $this->currentCompanyId(),
                ],
            ],
        );

        $this->savedViewName = '';
        session()->flash('success', __('Saved employee view updated.'));
    }

    public function applySavedView(int $viewId): void
    {
        if (! $this->savedEmployeeViewsTableExists()) {
            session()->flash('error', __('Saved employee views are unavailable until People settings tables are rebuilt.'));

            return;
        }

        $view = $this->savedViewsQuery()->findOrFail($viewId);
        $filters = is_array($view->filters) ? $view->filters : [];
        $sort = is_array($view->sort) ? $view->sort : [];

        foreach ($this->filterProperties() as $property) {
            $filterKey = $this->filterKeyForProperty($property);
            $value = $filters[$filterKey] ?? '';
            $this->{$property} = is_scalar($value) ? (string) $value : '';
        }

        $sortBy = (string) ($sort['by'] ?? '');
        if (array_key_exists($sortBy, self::SORTABLE)) {
            $this->sortBy = $sortBy;
        }

        $sortDir = strtolower((string) ($sort['dir'] ?? 'asc'));
        $this->sortDir = $sortDir === 'desc' ? 'desc' : 'asc';
        $this->resetPage();
    }

    public function statusVariant(string $status): string
    {
        return match ($status) {
            'active' => 'success',
            'terminated' => 'danger',
            'probation' => 'warning',
            'inactive', 'pending' => 'default',
            default => 'default',
        };
    }

    public function portalAccessVariant(?string $status): string
    {
        return match ($status) {
            'active' => 'success',
            'pending' => 'warning',
            'revoked' => 'danger',
            null, '' => 'default',
            default => 'default',
        };
    }

    public function readinessVariant(string $state): string
    {
        return $state === EmployeePayrollReadinessService::STATE_READY ? 'success' : 'warning';
    }

    public function render(
        EmployeeWorkbenchQuery $workbenchQuery,
        EmployeePayrollReadinessService $readiness,
    ): View {
        $companyIds = $this->companyTreeIds();
        $query = $workbenchQuery->build($companyIds);
        $workbenchQuery->applyFilters($query, $this->filters(), $readiness);

        $sortColumn = self::SORTABLE[$this->sortBy] ?? 'employees.full_name';
        $employees = $query
            ->orderBy($sortColumn, $this->sortDir)
            ->orderBy('employees.id')
            ->paginate(15);

        $employees->getCollection()->transform(function ($employee) use ($readiness) {
            $employee->setAttribute('payroll_readiness', $readiness->summarize($employee));

            return $employee;
        });

        return view('people-employees::livewire.people.employees.index', [
            'employees' => $employees,
            'companies' => Company::query()
                ->whereIn('id', $companyIds)
                ->orderBy('name')
                ->get(['id', 'name']),
            'organizationUnits' => $workbenchQuery->referenceOptions($companyIds, PeopleReferenceEntry::TYPE_ORGANIZATION_UNIT),
            'costCenters' => $workbenchQuery->referenceOptions($companyIds, PeopleReferenceEntry::TYPE_COST_CENTER),
            'employmentGroups' => $workbenchQuery->referenceOptions($companyIds, PeopleReferenceEntry::TYPE_EMPLOYMENT_GROUP),
            'jobTitles' => $workbenchQuery->referenceOptions($companyIds, PeopleReferenceEntry::TYPE_JOB_TITLE),
            'workforceClasses' => $workbenchQuery->referenceOptions($companyIds, PeopleReferenceEntry::TYPE_WORKFORCE_CLASS),
            'jobGrades' => $workbenchQuery->referenceOptions($companyIds, PeopleReferenceEntry::TYPE_JOB_GRADE),
            'workCalendars' => $workbenchQuery->referenceOptions($companyIds, PeopleReferenceEntry::TYPE_WORK_CALENDAR),
            'savedViews' => $this->savedEmployeeViewsTableExists() ? $this->savedViewsQuery()->get() : collect(),
            'readinessBlockers' => EmployeePayrollReadinessService::blockerLabels(),
            'exportUrl' => route('people.employees.export.csv', $this->filters()),
        ]);
    }

    /**
     * @return array<string, string>
     */
    private function filters(): array
    {
        return [
            'search' => $this->search,
            'status' => $this->status,
            'company_id' => $this->companyId,
            'organization_unit_id' => $this->organizationUnitId,
            'cost_center_id' => $this->costCenterId,
            'employment_group_id' => $this->employmentGroupId,
            'job_title_id' => $this->jobTitleId,
            'workforce_class_id' => $this->workforceClassId,
            'job_grade_id' => $this->jobGradeId,
            'work_calendar_id' => $this->workCalendarId,
            'pay_rate_type' => $this->payRateType,
            'portal_access_status' => $this->portalAccessStatus,
            'readiness_state' => $this->readinessState,
            'readiness_blocker' => $this->readinessBlocker,
        ];
    }

    /**
     * @return array<int, string>
     */
    private function filterProperties(): array
    {
        return [
            'search',
            'status',
            'companyId',
            'organizationUnitId',
            'costCenterId',
            'employmentGroupId',
            'jobTitleId',
            'workforceClassId',
            'jobGradeId',
            'workCalendarId',
            'payRateType',
            'portalAccessStatus',
            'readinessState',
            'readinessBlocker',
        ];
    }

    private function filterKeyForProperty(string $property): string
    {
        return match ($property) {
            'companyId' => 'company_id',
            'organizationUnitId' => 'organization_unit_id',
            'costCenterId' => 'cost_center_id',
            'employmentGroupId' => 'employment_group_id',
            'jobTitleId' => 'job_title_id',
            'workforceClassId' => 'workforce_class_id',
            'jobGradeId' => 'job_grade_id',
            'workCalendarId' => 'work_calendar_id',
            'payRateType' => 'pay_rate_type',
            'portalAccessStatus' => 'portal_access_status',
            'readinessState' => 'readiness_state',
            'readinessBlocker' => 'readiness_blocker',
            default => $property,
        };
    }

    /**
     * @return Builder<PeopleSavedEmployeeView>
     */
    private function savedViewsQuery(): Builder
    {
        return PeopleSavedEmployeeView::query()
            ->where('company_id', $this->currentCompanyId())
            ->where('status', 'active')
            ->where(function (Builder $query): void {
                $query->where('user_id', Auth::id())
                    ->orWhere('visibility', 'company');
            })
            ->orderBy('visibility')
            ->orderBy('name');
    }

    private function savedEmployeeViewsTableExists(): bool
    {
        return Schema::hasTable('people_saved_employee_views');
    }

    private function currentCompanyId(): int
    {
        return (int) (Auth::user()?->company_id ?? Company::LICENSEE_ID);
    }

    /**
     * @return list<int>
     */
    private function companyTreeIds(): array
    {
        $ids = [];
        $queue = [$this->currentCompanyId()];

        while ($queue !== []) {
            $batch = $queue;
            $queue = [];
            array_push($ids, ...$batch);

            $children = Company::query()
                ->whereIn('parent_id', $batch)
                ->pluck('id')
                ->all();

            array_push($queue, ...$children);
        }

        return array_values(array_unique($ids));
    }
}
