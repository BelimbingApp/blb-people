<?php

namespace App\Domains\People\Training\Livewire\Requests;

use App\Base\Authz\Contracts\AuthorizationService;
use App\Base\Authz\DTO\Actor;
use App\Base\Authz\Exceptions\AuthorizationDeniedException;
use App\Base\Foundation\Contracts\SemanticActionRecorder;
use App\Base\Foundation\Livewire\Concerns\ResetsPaginationOnSearch;
use App\Base\Foundation\Livewire\Concerns\SelectsPerPage;
use App\Base\Foundation\Livewire\Concerns\TogglesSort;
use App\Base\Locale\Contracts\CurrencyDisplayService;
use App\Base\Locale\Contracts\NumberDisplayService;
use App\Base\Tenancy\Contracts\TenantContext;
use App\Core\Company\Models\Company;
use App\Core\User\Models\User;
use App\Domains\People\Skills\Services\SkillAudience;
use App\Domains\People\Skills\Services\WorkforceSubjects;
use App\Domains\People\Training\Enums\TrainingRequestStatus;
use App\Domains\People\Training\Livewire\HrGovernance\Index as HrGovernanceIndex;
use App\Domains\People\Training\Models\TrainingEvent;
use App\Domains\People\Training\Models\TrainingRequest;
use App\Domains\People\Training\Models\TrainingRequestDecision;
use App\Domains\People\Training\Models\TrainingRequestSubject;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * HR register of training requests (plan 0010, 0010-c): every request of
 * the acting user's company for one year, with status, estimated cost,
 * department, approver and decision date; filters by status and
 * department; a CSV export of exactly the filtered rows that leaves one
 * audit action.
 *
 * Read-only. The company is one of the HR user's allowed companies and
 * every query is pinned to it; the filters narrow, never widen. Nothing
 * here decides: the HR queue and the request page own the workflow.
 */
final class Register extends Component
{
    use ResetsPaginationOnSearch;
    use SelectsPerPage;
    use TogglesSort;
    use WithPagination;

    public const VIEW_CAPABILITY = 'people.training.request.list';

    public const EXPORT_EVENT = 'people.training.requests.exported';

    /** A status filter value beyond the enum: approved requests with no event yet (0010-d). */
    public const FILTER_APPROVED_UNLINKED = 'approved_unlinked';

    /** Final decisions, whose actor is the approver and whose time is the decision date. */
    private const FINAL_DECISIONS = ['approved', 'rejected'];

    private const SORTABLE = [
        'created_at' => 'created_at',
        'need' => 'need',
        'status' => 'status',
        'estimated_cost' => 'estimated_cost',
    ];

    public ?int $companyEntityId = null;

    public int $year;

    /**
     * URL-bound so a reminder can link straight to the approved-unlinked view
     * (0009-h). A comma-separated list of statuses is honoured so the KPI
     * dashboard's "pending" drill can name all three pending states (#389).
     */
    #[Url]
    public string $status = '';

    #[Url]
    public string $department = '';

    #[Url]
    public string $search = '';

    #[Url]
    public string $sortBy = 'created_at';

    #[Url]
    public string $sortDir = 'desc';

    public ?int $selectedRequestId = null;

    /** @var array<string, string>|null */
    private ?array $allowedCompanies = null;

    public function mount(): void
    {
        $this->authorizeView();
        $companies = $this->allowedCompanies();
        $this->companyEntityId = $companies === [] ? null : (int) array_key_first($companies);
        $this->year = (int) now()->year;
    }

    public function selectCompany(int $companyEntityId): void
    {
        $this->authorizeView();
        abort_unless(array_key_exists($companyEntityId, $this->allowedCompanies()), 404);
        $this->companyEntityId = $companyEntityId;
        $this->reset('status', 'department', 'search', 'selectedRequestId');
        $this->resetPage();
    }

    public function updatedStatus(): void
    {
        $this->resetPage();
    }

    public function updatedDepartment(): void
    {
        $this->resetPage();
    }

    public function sort(string $column): void
    {
        $this->toggleSort(
            column: $column,
            allowedColumns: self::SORTABLE,
            defaultDir: [
                'created_at' => 'desc',
                'need' => 'asc',
                'status' => 'asc',
                'estimated_cost' => 'desc',
            ],
        );
    }

    public function openDetails(int $requestId): void
    {
        $companyEntityId = $this->requireCompany();
        abort_unless($this->baseRequestQuery($companyEntityId)->whereKey($requestId)->exists(), 404);
        $this->selectedRequestId = $requestId;
    }

    public function closeDetails(): void
    {
        $this->selectedRequestId = null;
    }

    public function render(): View
    {
        $this->authorizeView();
        $companies = $this->allowedCompanies();
        $companyEntityId = $this->companyEntityId === null ? null : $this->requireCompany();
        $departments = $companyEntityId === null ? [] : $this->departmentNames($companyEntityId);
        $employees = $companyEntityId === null ? [] : $this->employeeNames($companyEntityId);
        $rows = $companyEntityId === null
            ? new LengthAwarePaginator([], 0, $this->clampedPerPage())
            : $this->paginatedRows($companyEntityId, $departments, $employees);
        $selectedRequest = $companyEntityId === null || $this->selectedRequestId === null
            ? null
            : $this->selectedRequest($companyEntityId, $departments, $employees);

        return view('people::livewire.requests.register', [
            'companies' => $companies,
            'departments' => $departments,
            'statuses' => TrainingRequestStatus::cases(),
            'rows' => $rows,
            'selectedRequest' => $selectedRequest,
            'currencyCode' => $companyEntityId === null ? null : $this->companyCurrencyCode($companyEntityId),
            'canUseGovernance' => $this->canUseGovernance(),
        ]);
    }

    /** The filtered rows as CSV, and one audit action saying who exported what. */
    public function export(): StreamedResponse
    {
        $companyEntityId = $this->requireCompany();
        $departments = $this->departmentNames($companyEntityId);
        $employees = $this->employeeNames($companyEntityId);
        $rows = $this->decorateRequests(
            $companyEntityId,
            $this->filteredRequestQuery($companyEntityId, $departments, $employees)->get(),
            $departments,
            $employees,
        );
        $filename = sprintf('training-requests-%d-%d.csv', $companyEntityId, $this->year);

        app(SemanticActionRecorder::class)->record(
            event: self::EXPORT_EVENT,
            summary: __('Exported :count training requests of :year to CSV', ['count' => $rows->count(), 'year' => $this->year]),
            source: __('Training'),
            subject: ['name' => 'training-requests', 'identifier' => $filename],
            surface: 'people.training.requests.register',
            uiElement: 'export',
            context: [
                'company_entity_id' => $companyEntityId,
                'year' => $this->year,
                'status' => $this->status === '' ? null : $this->status,
                'department' => $this->department === '' ? null : $this->department,
                'rows' => $rows->count(),
                'request_ids' => $rows->pluck('id')->all(),
            ],
        );

        return response()->streamDownload(function () use ($rows): void {
            $out = fopen('php://output', 'wb');
            fputcsv($out, ['id', 'created_at', 'requestor', 'subjects', 'department', 'need', 'priority', 'status', 'estimated_cost', 'approver', 'decided_at', 'linked_event_id', 'linked_event_title']);
            foreach ($rows as $row) {
                fputcsv($out, [$row['id'], $row['created_at'], $row['requestor'], $row['subjects'], $row['department'], $row['need'], $row['priority'], $row['status'], $row['estimated_cost'], $row['approver'], $row['decided_at'], $row['linked_event_id'], $row['linked_event_title']]);
            }
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv']);
    }

    /**
     * @param  array<string, string>  $departments
     * @param  array<string, string>  $employees
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    private function paginatedRows(int $companyEntityId, array $departments, array $employees): LengthAwarePaginator
    {
        $paginator = $this->filteredRequestQuery($companyEntityId, $departments, $employees)
            ->paginate($this->clampedPerPage());
        $paginator->setCollection($this->decorateRequests(
            $companyEntityId,
            $paginator->getCollection(),
            $departments,
            $employees,
        ));

        return $paginator;
    }

    /**
     * @param  array<string, string>  $departments
     * @param  array<string, string>  $employees
     */
    private function filteredRequestQuery(int $companyEntityId, array $departments, array $employees): Builder
    {
        $query = $this->baseRequestQuery($companyEntityId);

        if ($this->status === self::FILTER_APPROVED_UNLINKED) {
            $query->where('status', TrainingRequestStatus::Approved->value)->whereNull('training_event_id');
        } elseif ($this->status !== '') {
            $statuses = array_values(array_filter(explode(',', $this->status), static fn (string $s): bool => TrainingRequestStatus::tryFrom($s) !== null));
            if ($statuses !== []) {
                $query->whereIn('status', $statuses);
            }
        }
        if ($this->department !== '' && array_key_exists($this->department, $departments)) {
            $query->where('department_subject_id', $this->department);
        }

        $search = trim($this->search);
        if ($search !== '') {
            $matchingEmployees = array_keys(array_filter(
                $employees,
                static fn (string $name): bool => str_contains(Str::lower($name), Str::lower($search)),
            ));
            $matchingDepartments = array_keys(array_filter(
                $departments,
                static fn (string $name): bool => str_contains(Str::lower($name), Str::lower($search)),
            ));
            $matchingStatuses = array_values(array_map(
                static fn (TrainingRequestStatus $status): string => $status->value,
                array_filter(
                    TrainingRequestStatus::cases(),
                    static fn (TrainingRequestStatus $status): bool => str_contains(Str::lower($status->label()), Str::lower($search))
                        || str_contains($status->value, Str::lower($search)),
                ),
            ));

            $matches = DB::table('people_training_requests')
                ->select('id')
                ->where('tenant_id', $this->tenantId())
                ->where('company_entity_id', $companyEntityId)
                ->where(function ($query) use ($search, $matchingEmployees, $matchingDepartments, $matchingStatuses): void {
                    $query->whereLike('need', '%'.$search.'%')
                        ->orWhereLike('learning_objective', '%'.$search.'%')
                        ->orWhereLike('expected_result', '%'.$search.'%');
                    if ($matchingEmployees !== []) {
                        $query->orWhereIn('requestor_subject_id', $matchingEmployees);
                    }
                    if ($matchingDepartments !== []) {
                        $query->orWhereIn('department_subject_id', $matchingDepartments);
                    }
                    if ($matchingStatuses !== []) {
                        $query->orWhereIn('status', $matchingStatuses);
                    }
                });

            $query->whereIn('id', $matches);
        }

        $sortColumn = self::SORTABLE[$this->sortBy] ?? self::SORTABLE['created_at'];

        return $query->orderBy($sortColumn, $this->sortDir === 'asc' ? 'asc' : 'desc')
            ->orderByDesc('id');
    }

    private function baseRequestQuery(int $companyEntityId): Builder
    {
        return TrainingRequest::query()
            ->forCompany($this->tenantId(), $companyEntityId)
            ->whereBetween('created_at', [sprintf('%d-01-01 00:00:00', $this->year), sprintf('%d-12-31 23:59:59', $this->year)]);
    }

    /**
     * @param  Collection<int, TrainingRequest>  $requests
     * @param  array<string, string>  $departments
     * @param  array<string, string>  $employees
     * @return Collection<int, array<string, mixed>>
     */
    private function decorateRequests(int $companyEntityId, Collection $requests, array $departments, array $employees): Collection
    {
        $tenantId = $this->tenantId();

        $decisionRows = $requests->isEmpty() ? collect() : TrainingRequestDecision::query()
            ->forCompany($tenantId, $companyEntityId)
            ->whereIn('training_request_id', $requests->pluck('id')->all())
            ->orderBy('id')->get();
        $decisions = $decisionRows->groupBy('training_request_id');
        $actors = $decisionRows->isEmpty() ? collect() : User::query()
            ->whereIn('id', $decisionRows->pluck('actor_user_id')->unique()->all())
            ->get()->keyBy('id');
        $linkedIds = $requests->pluck('training_event_id')->filter()->unique()->all();
        $eventTitles = $linkedIds === [] ? collect() : TrainingEvent::query()->forCompany($tenantId, $companyEntityId)->whereIn('id', $linkedIds)->pluck('course_title_snapshot', 'id');
        $subjectCounts = $requests->isEmpty() ? collect() : TrainingRequestSubject::query()
            ->forCompany($tenantId, $companyEntityId)
            ->whereIn('training_request_id', $requests->pluck('id')->all())
            ->get(['training_request_id'])
            ->countBy('training_request_id');
        $currencyCode = $this->companyCurrencyCode($companyEntityId);

        return $requests->map(function (TrainingRequest $request) use ($decisions, $actors, $employees, $departments, $eventTitles, $subjectCounts, $currencyCode): array {
            $history = $decisions->get($request->id, collect());
            $finalDecision = $history->last(static fn (TrainingRequestDecision $decision): bool => in_array($decision->decision, self::FINAL_DECISIONS, true));
            $cost = $request->estimated_cost === null ? null : (float) $request->estimated_cost;

            return [
                'id' => (int) $request->id,
                'created_at' => (string) $request->created_at?->toDateString(),
                'created_at_value' => $request->created_at,
                'requestor' => $employees[$request->requestor_subject_id] ?? __('Employee :id', ['id' => $request->requestor_subject_id]),
                'subjects' => (int) ($subjectCounts[$request->id] ?? 0),
                'department' => $departments[$request->department_subject_id] ?? $request->department_subject_id,
                'need' => (string) $request->need,
                'learning_objective' => (string) $request->learning_objective,
                'expected_result' => (string) $request->expected_result,
                'priority' => $request->priority->value,
                'priority_label' => Str::headline($request->priority->value),
                'status' => $request->status->value,
                'status_label' => $request->status->label(),
                'estimated_cost' => $request->estimated_cost === null ? '' : (string) $request->estimated_cost,
                'estimated_cost_display' => $cost === null ? null : $this->formatCost($cost, $currencyCode),
                'approver' => $finalDecision === null ? '' : (string) ($actors->get((int) $finalDecision->actor_user_id)?->name ?? ''),
                'decided_at' => $finalDecision === null ? '' : (string) $finalDecision->occurred_at?->toDateString(),
                'decided_at_value' => $finalDecision?->occurred_at,
                'linked_event_id' => $request->training_event_id === null ? '' : (string) $request->training_event_id,
                'linked_event_title' => $request->training_event_id === null ? '' : (string) ($eventTitles[(int) $request->training_event_id] ?? ''),
                'decisions' => $history->map(static fn (TrainingRequestDecision $decision): array => [
                    'decision' => (string) $decision->decision,
                    'label' => Str::headline((string) $decision->decision),
                    'actor' => (string) ($actors->get((int) $decision->actor_user_id)?->name ?? __('Unknown user')),
                    'notes' => (string) ($decision->notes ?? ''),
                    'occurred_at' => $decision->occurred_at,
                ])->values(),
            ];
        })->values();
    }

    /**
     * @param  array<string, string>  $departments
     * @param  array<string, string>  $employees
     * @return array<string, mixed>
     */
    private function selectedRequest(int $companyEntityId, array $departments, array $employees): array
    {
        $request = $this->baseRequestQuery($companyEntityId)->findOrFail($this->selectedRequestId);

        return $this->decorateRequests($companyEntityId, collect([$request]), $departments, $employees)->sole();
    }

    private function formatCost(float $cost, ?string $currencyCode): string
    {
        return $currencyCode === null
            ? app(NumberDisplayService::class)->format($cost, 2, 2)
            : app(CurrencyDisplayService::class)->format($cost, $currencyCode);
    }

    private function companyCurrencyCode(int $companyEntityId): ?string
    {
        $company = Company::query()->forTenant($this->tenantId())->find($companyEntityId);
        $currencyCode = strtoupper((string) ($company?->primaryAddress()?->country?->currency_code ?? ''));

        return preg_match('/^[A-Z]{3}$/', $currencyCode) === 1 ? $currencyCode : null;
    }

    private function canUseGovernance(): bool
    {
        return app(AuthorizationService::class)
            ->can(Actor::forUser($this->user()), HrGovernanceIndex::VIEW_CAPABILITY)
            ->allowed;
    }

    /** @return array<string, string> organization-unit stable id => name */
    private function departmentNames(int $companyEntityId): array
    {
        $names = [];
        foreach (app(WorkforceSubjects::class)->organizationUnits($companyEntityId) as $unit) {
            $names[$unit->reference->externalId] = $unit->name;
        }

        return $names;
    }

    /** @return array<string, string> employee stable id => display name */
    private function employeeNames(int $companyEntityId): array
    {
        $names = [];
        foreach (app(WorkforceSubjects::class)->employees($companyEntityId) as $employee) {
            $names[$employee->reference->externalId] = $employee->displayName;
        }

        return $names;
    }

    private function requireCompany(): int
    {
        $this->authorizeView();
        $companyEntityId = $this->companyEntityId;
        abort_unless($companyEntityId !== null && array_key_exists($companyEntityId, $this->allowedCompanies()), 404);

        return $companyEntityId;
    }

    private function authorizeView(): void
    {
        try {
            $audiences = app(SkillAudience::class)->authorizeAudience($this->user(), self::VIEW_CAPABILITY);
        } catch (AuthorizationDeniedException) {
            abort(403);
        }

        abort_unless(in_array(SkillAudience::HR, $audiences, true), 403);
    }

    /** @return array<int, string> */
    private function allowedCompanies(): array
    {
        return $this->allowedCompanies ??= app(SkillAudience::class)->allowedCompanies($this->user(), self::VIEW_CAPABILITY);
    }

    private function user(): User
    {
        $user = Auth::user();
        abort_unless($user instanceof User, 403);

        return $user;
    }

    private function tenantId(): int
    {
        return app(TenantContext::class)->requireTenantId();
    }
}
