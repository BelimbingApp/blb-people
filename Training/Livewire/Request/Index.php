<?php

namespace App\Domains\People\Training\Livewire\Request;

use App\Base\Authz\Exceptions\AuthorizationDeniedException;
use App\Base\Tenancy\Contracts\TenantContext;
use App\Core\User\Models\User;
use App\Domains\People\Provider\Data\WorkforceEmployee;
use App\Domains\People\Provider\Data\WorkforceSubject;
use App\Domains\People\Provider\Enums\WorkforceResourceType;
use App\Domains\People\Skills\Services\SkillAudience;
use App\Domains\People\Skills\Services\WorkforceSubjects;
use App\Domains\People\Training\Data\TrainingRequestDraft;
use App\Domains\People\Training\Enums\TrainingNeedSource;
use App\Domains\People\Training\Enums\TrainingPriority;
use App\Domains\People\Training\Enums\TrainingRequestStatus;
use App\Domains\People\Training\Exceptions\InvalidTrainingRequestException;
use App\Domains\People\Training\Models\TrainingRequest;
use App\Domains\People\Training\Models\TrainingRequestDecision;
use App\Domains\People\Training\Services\TrainingRequestStore;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Component;

/**
 * Training request page (plan 0005, 0005-i): an employee drafts a request
 * for themself, a HOD drafts for a department member and recommends, and
 * both track where each request stands.
 *
 * Who may be the requestor is the Skills audience answer for the submit
 * capability: the bound employee for the employee audience, the people a
 * HOD manages or heads for the HOD audience. A request is listed when its
 * requestor is one of those people or its department is one the HOD heads.
 * Every write is the store's own method with the store's capability and
 * company checks; the status shown is the row's, never computed here. The
 * HR queue owns review and approval, so neither is offered.
 */
final class Index extends Component
{
    public const CAPABILITY = TrainingRequestStore::SUBMIT;

    public ?int $companyEntityId = null;

    public string $requestorEntityId = '';

    public string $needSource = TrainingNeedSource::NewMachineTechnology->value;

    public string $need = '';

    public string $learningObjective = '';

    public string $expectedResult = '';

    public string $priority = TrainingPriority::Medium->value;

    /** @var array<int, string> */
    public array $recommendNotes = [];

    /** @var array<string, string>|null */
    private ?array $allowedCompanies = null;

    public function mount(): void
    {
        $this->authorizeView();
        $companies = $this->allowedCompanies();
        $this->companyEntityId = $companies === [] ? null : (int) array_key_first($companies);
        $this->requestorEntityId = $this->companyEntityId === null ? '' : (string) ($this->selfEntityId($this->companyEntityId) ?? '');
    }

    public function selectCompany(int $companyEntityId): void
    {
        $this->authorizeView();
        abort_unless(array_key_exists($companyEntityId, $this->allowedCompanies()), 404);
        $this->companyEntityId = $companyEntityId;
        $this->requestorEntityId = (string) ($this->selfEntityId($companyEntityId) ?? '');
    }

    public function draft(): void
    {
        $companyEntityId = $this->requireCompany();
        $this->validate([
            'requestorEntityId' => ['required', 'integer'],
            'needSource' => ['required', Rule::enum(TrainingNeedSource::class)],
            'priority' => ['required', Rule::enum(TrainingPriority::class)],
            'need' => ['required', 'string', 'max:2000'],
            'learningObjective' => ['required', 'string', 'max:2000'],
            'expectedResult' => ['required', 'string', 'max:2000'],
        ]);

        // The requestor is client-chosen, so it is checked against the
        // audience's own people before the store hears of it: an employee
        // naming a colleague, or a HOD naming someone outside the department,
        // is refused here as a denial, not validated as a bad choice.
        $requestor = $this->eligibleEmployees($companyEntityId)[(int) $this->requestorEntityId] ?? null;
        abort_if($requestor === null, 403);

        if ($requestor->organizationReference === null) {
            $this->addError('requestorEntityId', __('The requestor has no department to route the request through.'));

            return;
        }

        $tenantId = $this->tenantId();
        $this->storeCall(fn () => app(TrainingRequestStore::class)->create($this->user(), $companyEntityId, new TrainingRequestDraft(
            requestor: new WorkforceSubject($tenantId, $companyEntityId, WorkforceResourceType::Employee, $requestor->reference->externalId),
            department: new WorkforceSubject($tenantId, $companyEntityId, WorkforceResourceType::OrganizationUnit, $requestor->organizationReference->externalId),
            needSource: TrainingNeedSource::from($this->needSource),
            need: $this->need,
            learningObjective: $this->learningObjective,
            expectedResult: $this->expectedResult,
            priority: TrainingPriority::from($this->priority),
        )));

        $this->reset('need', 'learningObjective', 'expectedResult');
    }

    public function submitRequest(int $requestId): void
    {
        $companyEntityId = $this->requireCompany();
        // Reaching the row is the check: a tracked request is one the user
        // may act for, and the store refuses anything not in draft.
        $this->trackedRequest($companyEntityId, $requestId);

        $this->storeCall(fn () => app(TrainingRequestStore::class)->submit($this->user(), $companyEntityId, $requestId));
    }

    public function recommend(int $requestId): void
    {
        $companyEntityId = $this->requireCompany();
        $request = $this->trackedRequest($companyEntityId, $requestId);
        // A HOD recommends only for departments they head; a request that is
        // merely visible (their own, as an employee) is not theirs to recommend.
        abort_unless(in_array($request->department_subject_id, $this->hodDepartments($companyEntityId), true), 403);

        $this->storeCall(function () use ($companyEntityId, $requestId): void {
            $notes = trim($this->recommendNotes[$requestId] ?? '');
            app(TrainingRequestStore::class)->recommend($this->user(), $companyEntityId, $requestId, $notes === '' ? null : $notes);
            unset($this->recommendNotes[$requestId]);
        });
    }

    public function render(): View
    {
        $this->authorizeView();
        $companies = $this->allowedCompanies();
        $companyEntityId = $this->companyEntityId === null ? null : $this->requireCompany();
        $employees = $companyEntityId === null ? [] : $this->eligibleEmployees($companyEntityId);
        $hodDepartments = $companyEntityId === null ? [] : $this->hodDepartments($companyEntityId);
        $requests = $companyEntityId === null ? collect() : $this->trackedRequests($companyEntityId, $employees, $hodDepartments);

        return view('people::livewire.request.index', [
            'companies' => $companies,
            'employees' => collect($employees)->map(fn (WorkforceEmployee $e): string => $e->displayName)->all(),
            'departments' => $companyEntityId === null ? [] : $this->departmentNames($companyEntityId),
            'requests' => $requests,
            'editable' => $requests->filter(fn (TrainingRequest $r): bool => $r->status === TrainingRequestStatus::Draft)->pluck('id')->all(),
            'recommendable' => $requests->filter(fn (TrainingRequest $r): bool => $r->status === TrainingRequestStatus::PendingHod && in_array($r->department_subject_id, $hodDepartments, true))->pluck('id')->all(),
            'needSources' => TrainingNeedSource::cases(),
            'priorities' => TrainingPriority::cases(),
        ]);
    }

    /**
     * The people this user may name as requestor, keyed by employee entity
     * id: themself for the employee audience, the department for a HOD.
     *
     * @return array<int, WorkforceEmployee>
     */
    private function eligibleEmployees(int $companyEntityId): array
    {
        $visible = app(SkillAudience::class)->visibleEmployeeEntityIdsFor($this->user(), $companyEntityId, self::CAPABILITY, includeSelf: true);
        $employees = [];
        foreach (app(WorkforceSubjects::class)->employees($companyEntityId) as $employee) {
            $id = (int) $employee->reference->externalId;
            if ($employee->active && in_array($id, $visible, true)) {
                $employees[$id] = $employee;
            }
        }

        return $employees;
    }

    /** @return list<string> organization-unit stable ids the user heads */
    private function hodDepartments(int $companyEntityId): array
    {
        try {
            $ids = app(SkillAudience::class)->visibleOrganizationUnitEntityIds($this->user(), $companyEntityId, TrainingRequestStore::HOD_RECOMMEND);
        } catch (AuthorizationDeniedException) {
            return [];
        }

        return array_map(strval(...), $ids);
    }

    /**
     * @param  array<int, WorkforceEmployee>  $employees
     * @param  list<string>  $hodDepartments
     * @return Collection<int, TrainingRequest>
     */
    private function trackedRequests(int $companyEntityId, array $employees, array $hodDepartments): Collection
    {
        // Two pinned queries rather than one OR group: the company-scope
        // guard accepts only a plainly pinned query, which is the point of it.
        $rows = collect();
        foreach (['requestor_subject_id' => array_map(strval(...), array_keys($employees)), 'department_subject_id' => $hodDepartments] as $column => $ids) {
            if ($ids !== []) {
                $rows = $rows->merge(TrainingRequest::query()->forCompany($this->tenantId(), $companyEntityId)->whereIn($column, $ids)->get());
            }
        }

        $rows = $rows->unique('id')->sortByDesc('id')->values();

        // Decisions are attached from one explicitly pinned query: the
        // relation's own company pin comes from the row, which an eager
        // load built on a blank model does not have.
        $decisions = $rows->isEmpty() ? collect() : TrainingRequestDecision::query()
            ->forCompany($this->tenantId(), $companyEntityId)
            ->whereIn('training_request_id', $rows->pluck('id')->all())
            ->orderBy('id')->get()->groupBy('training_request_id');
        $rows->each(fn (TrainingRequest $request) => $request->setRelation('decisions', $decisions->get($request->id, collect())));

        return $rows;
    }

    private function trackedRequest(int $companyEntityId, int $requestId): TrainingRequest
    {
        return $this->trackedRequests($companyEntityId, $this->eligibleEmployees($companyEntityId), $this->hodDepartments($companyEntityId))
            ->firstWhere('id', $requestId) ?? abort(404);
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

    private function selfEntityId(int $companyEntityId): ?int
    {
        return app(SkillAudience::class)->boundEmployeeEntityId($this->user(), $companyEntityId);
    }

    /**
     * A store's capability refusal is the page's answer too (403); its
     * domain refusal (wrong state, missing text) is a form error.
     */
    private function storeCall(\Closure $action): void
    {
        try {
            $action();
        } catch (AuthorizationDeniedException) {
            abort(403);
        } catch (InvalidTrainingRequestException $refusal) {
            $this->addError('request', $refusal->getMessage());
        }
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
            app(SkillAudience::class)->authorizeAudience($this->user(), self::CAPABILITY);
        } catch (AuthorizationDeniedException) {
            abort(403);
        }
    }

    /** @return array<int, string> */
    private function allowedCompanies(): array
    {
        return $this->allowedCompanies ??= app(SkillAudience::class)->allowedCompanies($this->user(), self::CAPABILITY);
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
