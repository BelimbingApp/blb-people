<?php

namespace App\Domains\People\Training\Livewire\Requests;

use App\Base\Authz\Exceptions\AuthorizationDeniedException;
use App\Base\Foundation\Contracts\SemanticActionRecorder;
use App\Base\Tenancy\Contracts\TenantContext;
use App\Core\User\Models\User;
use App\Domains\People\Skills\Services\SkillAudience;
use App\Domains\People\Skills\Services\WorkforceSubjects;
use App\Domains\People\Training\Enums\TrainingRequestStatus;
use App\Domains\People\Training\Models\TrainingEvent;
use App\Domains\People\Training\Models\TrainingRequest;
use App\Domains\People\Training\Models\TrainingRequestDecision;
use App\Domains\People\Training\Models\TrainingRequestSubject;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Url;
use Livewire\Component;
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
    public const VIEW_CAPABILITY = 'people.training.request.list';

    public const EXPORT_EVENT = 'people.training.requests.exported';

    /** A status filter value beyond the enum: approved requests with no event yet (0010-d). */
    public const FILTER_APPROVED_UNLINKED = 'approved_unlinked';

    /** Final decisions, whose actor is the approver and whose time is the decision date. */
    private const FINAL_DECISIONS = ['approved', 'rejected'];

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
        $this->reset('status', 'department');
    }

    public function render(): View
    {
        $this->authorizeView();
        $companies = $this->allowedCompanies();
        $companyEntityId = $this->companyEntityId === null ? null : $this->requireCompany();
        $departments = $companyEntityId === null ? [] : $this->departmentNames($companyEntityId);

        return view('people::livewire.requests.register', [
            'companies' => $companies,
            'departments' => $departments,
            'statuses' => TrainingRequestStatus::cases(),
            'rows' => $companyEntityId === null ? collect() : $this->rows($companyEntityId, $departments),
        ]);
    }

    /** The filtered rows as CSV, and one audit action saying who exported what. */
    public function export(): StreamedResponse
    {
        $companyEntityId = $this->requireCompany();
        $rows = $this->rows($companyEntityId, $this->departmentNames($companyEntityId));
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
     * @return Collection<int, array{id: int, created_at: string, requestor: string, subjects: int, department: string, need: string, priority: string, status: string, estimated_cost: string, approver: string, decided_at: string}>
     */
    private function rows(int $companyEntityId, array $departments): Collection
    {
        $tenantId = $this->tenantId();
        $query = TrainingRequest::query()
            ->forCompany($tenantId, $companyEntityId)
            ->whereBetween('created_at', [sprintf('%d-01-01 00:00:00', $this->year), sprintf('%d-12-31 23:59:59', $this->year)])
            ->orderByDesc('id');
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
        $requests = $query->get();

        // Decisions and approver names from two more pinned queries: an eager
        // load would build the relation on a blank model and pin company 0.
        $decisions = $requests->isEmpty() ? collect() : TrainingRequestDecision::query()
            ->forCompany($tenantId, $companyEntityId)
            ->whereIn('training_request_id', $requests->pluck('id')->all())
            ->whereIn('decision', self::FINAL_DECISIONS)
            ->orderBy('id')->get()->keyBy('training_request_id');
        $approvers = $decisions->isEmpty() ? collect() : User::query()->whereIn('id', $decisions->pluck('actor_user_id')->unique()->all())->get()->keyBy('id');
        $linkedIds = $requests->pluck('training_event_id')->filter()->unique()->all();
        $eventTitles = $linkedIds === [] ? collect() : TrainingEvent::query()->forCompany($tenantId, $companyEntityId)->whereIn('id', $linkedIds)->pluck('course_title_snapshot', 'id');
        // How many people each request is for (0010-f). One more pinned
        // query for the same reason as the two above: an eager load would
        // build the relation on a blank model and pin company 0.
        $subjectCounts = $requests->isEmpty() ? collect() : TrainingRequestSubject::query()
            ->forCompany($tenantId, $companyEntityId)
            ->whereIn('training_request_id', $requests->pluck('id')->all())
            ->get(['training_request_id'])
            ->countBy('training_request_id');
        $employees = [];
        foreach (app(WorkforceSubjects::class)->employees($companyEntityId) as $employee) {
            $employees[$employee->reference->externalId] = $employee->displayName;
        }

        return $requests->map(function (TrainingRequest $request) use ($decisions, $approvers, $employees, $departments, $eventTitles, $subjectCounts): array {
            $decision = $decisions->get($request->id);
            $approver = $decision === null ? null : $approvers->get((int) $decision->actor_user_id);

            return [
                'id' => (int) $request->id,
                'created_at' => (string) $request->created_at?->toDateString(),
                'requestor' => $employees[$request->requestor_subject_id] ?? __('Employee :id', ['id' => $request->requestor_subject_id]),
                'subjects' => (int) ($subjectCounts[$request->id] ?? 0),
                'department' => $departments[$request->department_subject_id] ?? $request->department_subject_id,
                'need' => (string) $request->need,
                'priority' => $request->priority->value,
                'status' => $request->status->value,
                'estimated_cost' => $request->estimated_cost === null ? '' : (string) $request->estimated_cost,
                'approver' => $approver?->name ?? '',
                'decided_at' => $decision === null ? '' : (string) $decision->occurred_at?->toDateString(),
                'linked_event_id' => $request->training_event_id === null ? '' : (string) $request->training_event_id,
                'linked_event_title' => $request->training_event_id === null ? '' : (string) ($eventTitles[(int) $request->training_event_id] ?? ''),
            ];
        })->values();
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
