<?php

namespace App\Domains\People\Skills\Livewire\Planning;

use App\Base\Authz\Contracts\AuthorizationService;
use App\Base\Authz\DTO\Actor;
use App\Base\Authz\Exceptions\AuthorizationDeniedException;
use App\Base\Tenancy\Contracts\TenantContext;
use App\Domains\People\Provider\Data\WorkforceSubject;
use App\Domains\People\Provider\Enums\WorkforceResourceType;
use App\Domains\People\Settings\Models\PeopleReferenceEntry;
use App\Domains\People\Skills\Data\DevelopmentActionDraft;
use App\Domains\People\Skills\Enums\DevelopmentActionType;
use App\Domains\People\Skills\Exceptions\InvalidDevelopmentActionException;
use App\Domains\People\Skills\Models\Skill;
use App\Domains\People\Skills\Models\SkillAssessment;
use App\Domains\People\Skills\Services\CompanyAttribution;
use App\Domains\People\Skills\Services\DevelopmentActionStore;
use App\Domains\People\Skills\Services\SkillAudience;
use App\Domains\People\Skills\Services\WorkforceSubjects;
use App\Domains\People\Training\Data\TrainingRequestDraft;
use App\Domains\People\Training\Enums\TrainingNeedSource;
use App\Domains\People\Training\Enums\TrainingPriority;
use App\Domains\People\Training\Enums\TrainingRequestStatus;
use App\Domains\People\Training\Exceptions\InvalidTrainingRequestException;
use App\Domains\People\Training\Models\TrainingRequest;
use App\Domains\People\Training\Services\TrainingRequestStore;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

/**
 * HOD planning — gap coverage and open needs for the HOD's assigned
 * departments, with propose-only actions.
 *
 * Scoping reuses SkillAudience (units, employees); every proposal
 * re-checks the capability and the employee scope before calling the owning
 * store. No approval actions live here.
 */
class Index extends Component
{
    public ?int $companyEntityId = null;

    public ?string $confirmation = null;

    /** @var array<string, string> employeeId => text */
    public array $reqNeed = [];

    /** @var array<string, string> employeeId => text */
    public array $reqObjective = [];

    /** @var array<string, string> employeeId => text */
    public array $reqResult = [];

    /** @var array<string, string> assessmentId => text */
    public array $daObjective = [];

    /** @var array<string, string> assessmentId => text */
    public array $daIntervention = [];

    /** @var array<string, string> assessmentId => text */
    public array $daEvidence = [];

    /** @var array<string, string> assessmentId => action type value */
    public array $daType = [];

    /** @var array<string, string> assessmentId => trainer employee id */
    public array $daTrainer = [];

    public function mount(): void
    {
        $this->authorizeView();
        $this->companyEntityId = (int) Auth::user()->company_id;
    }

    /**
     * Draft a training request for an in-scope employee. The owning store
     * enforces the submit capability; the page pre-check turns that denial
     * into a 403 instead of a 500.
     */
    public function draftTrainingRequest(string $employeeId): void
    {
        $companyEntityId = $this->authorizedCompany();
        $this->authorizeSubmit();
        $employee = $this->scopedEmployee($companyEntityId, $employeeId);
        $unitId = $employee['unitId'];

        if ($unitId === null) {
            $this->addError('planning', __('The employee has no department assignment.'));

            return;
        }

        try {
            app(TrainingRequestStore::class)->create(
                Auth::user(),
                $companyEntityId,
                new TrainingRequestDraft(
                    requestor: $this->subject($companyEntityId, WorkforceResourceType::Employee, $employeeId),
                    department: $this->subject($companyEntityId, WorkforceResourceType::OrganizationUnit, (string) $unitId),
                    needSource: TrainingNeedSource::PerformanceImprovement,
                    need: trim((string) ($this->reqNeed[$employeeId] ?? '')),
                    learningObjective: trim((string) ($this->reqObjective[$employeeId] ?? '')),
                    expectedResult: trim((string) ($this->reqResult[$employeeId] ?? '')),
                    priority: TrainingPriority::Medium,
                ),
            );
        } catch (InvalidTrainingRequestException $exception) {
            $this->addError('planning', $exception->getMessage());

            return;
        }

        $this->reset('reqNeed', 'reqObjective', 'reqResult');
        $this->confirmation = __('Training request drafted.');
    }

    /**
     * Propose a development action for an in-scope finalized gap. Approval
     * stays with the owning workflow; this page only proposes.
     */
    public function proposeDevelopmentAction(string $assessmentId): void
    {
        $companyEntityId = $this->authorizedCompany();
        $this->authorizeManage();
        $type = trim((string) ($this->daType[$assessmentId] ?? ''));
        $actionType = DevelopmentActionType::tryFrom($type === '' ? DevelopmentActionType::Coaching->value : $type);

        if ($actionType === null) {
            $this->addError('planning', __('Unknown development action type.'));

            return;
        }

        $assessment = SkillAssessment::query()
            ->forCompany(app(TenantContext::class)->requireTenantId(), $companyEntityId)
            ->whereKey($assessmentId)
            ->where('status', 'finalized')
            ->where('gap', '>', 0)
            ->first();

        $scoped = $this->scopedEmployeeIds($companyEntityId);

        abort_unless(
            $assessment !== null
            && in_array((int) $assessment->employee_entity_id, $scoped, true),
            404,
        );

        $trainerRaw = trim((string) ($this->daTrainer[$assessmentId] ?? ''));

        if ($trainerRaw === '' || ! ctype_digit($trainerRaw)) {
            $this->addError('planning', __('Choose a trainer from your department.'));

            return;
        }

        abort_unless(in_array((int) $trainerRaw, $scoped, true), 404);

        try {
            app(DevelopmentActionStore::class)->proposeFromAssessments(
                $companyEntityId,
                [(int) $assessment->id],
                new DevelopmentActionDraft(
                    employeeEntityId: (int) $assessment->employee_entity_id,
                    type: $actionType,
                    objective: trim((string) ($this->daObjective[$assessmentId] ?? '')),
                    intervention: trim((string) ($this->daIntervention[$assessmentId] ?? '')),
                    expectedEvidence: trim((string) ($this->daEvidence[$assessmentId] ?? '')),
                    ownerEmployeeEntityId: (int) $assessment->employee_entity_id,
                    hrCoordinatorEmployeeEntityId: (int) $assessment->employee_entity_id,
                    startDate: now()->addDay(),
                    dueDate: now()->addMonth(),
                    trainerEmployeeEntityId: (int) $trainerRaw,
                ),
                Auth::id(),
            );
        } catch (InvalidDevelopmentActionException $exception) {
            $this->addError('planning', $exception->getMessage());

            return;
        }

        $this->confirmation = __('Development action proposed.');
    }

    public function render(): View
    {
        $companyEntityId = $this->authorizedCompany();
        $names = $this->units($companyEntityId);
        $employees = $this->employees($companyEntityId);
        $gaps = $this->gaps($companyEntityId, $employees);
        $needs = $this->needs($companyEntityId, $employees);

        $units = [];

        foreach ($names as $unitId => $unitName) {
            $units[] = [
                'id' => $unitId,
                'name' => $unitName,
                'employees' => array_values(array_filter(
                    $employees,
                    fn (array $employee): bool => $employee['unitId'] === $unitId,
                )),
                'gaps' => array_values(array_filter(
                    $gaps,
                    fn (array $gap): bool => $gap['unitId'] === $unitId,
                )),
                'needs' => array_values(array_filter(
                    $needs,
                    fn (array $need): bool => $need['unitId'] === $unitId,
                )),
            ];
        }

        return view('people::livewire.planning.index', [
            'units' => $units,
        ]);
    }

    /**
     * @return array<int, string> unit entity id => name, HOD scope only
     */
    private function units(int $companyEntityId): array
    {
        $ids = app(SkillAudience::class)->visibleOrganizationUnitEntityIds(
            Auth::user(),
            $companyEntityId,
            'people.skill.assessment.view',
        );

        if ($ids === []) {
            return [];
        }

        return PeopleReferenceEntry::query()
            ->where('company_id', $companyEntityId)
            ->whereIn('id', $ids)
            ->orderBy('name')
            ->pluck('name', 'id')
            ->map(fn ($name): string => (string) $name)
            ->all();
    }

    /**
     * @return array<int, array{id: int, name: string, unitId: ?int, position: ?string}>
     */
    private function employees(int $companyEntityId): array
    {
        $audience = app(SkillAudience::class);
        $visible = $audience->visibleEmployeeEntityIdsFor(
            Auth::user(),
            $companyEntityId,
            'people.skill.assessment.view',
        );
        $unitIds = array_keys($this->units($companyEntityId));
        $positions = PeopleReferenceEntry::query()
            ->where('company_id', $companyEntityId)
            ->where('type', PeopleReferenceEntry::TYPE_JOB_TITLE)
            ->pluck('name', 'id');

        $employees = [];

        foreach (app(WorkforceSubjects::class)->employees($companyEntityId) as $employee) {
            $id = (int) $employee->reference->externalId;

            if (! in_array($id, $visible, true)) {
                continue;
            }

            $unitId = $employee->organizationReference === null
                ? null : (int) $employee->organizationReference->externalId;

            if ($unitId === null || ! in_array($unitId, $unitIds, true)) {
                continue;
            }

            $employees[$id] = [
                'id' => $id,
                'name' => $employee->displayName,
                'unitId' => $unitId,
                'position' => $employee->positionReference === null
                    ? null : (string) ($positions[$employee->positionReference->externalId] ?? $employee->positionReference->externalId),
            ];
        }

        return $employees;
    }

    /**
     * @param  array<int, array{id: int, name: string, unitId: ?int, position: ?string}>  $employees
     * @return list<array{id: int, employee: string, unitId: int, skill: string, reference: string, required: int, assessed: ?int, gap: int}>
     */
    private function gaps(int $companyEntityId, array $employees): array
    {
        if ($employees === []) {
            return [];
        }

        $skills = Skill::query()
            ->forCompany(app(TenantContext::class)->requireTenantId(), $companyEntityId)
            ->pluck('name', 'id');

        return SkillAssessment::query()
            ->forCompany(app(TenantContext::class)->requireTenantId(), $companyEntityId)
            ->whereIn('employee_entity_id', array_keys($employees))
            ->where('status', 'finalized')
            ->where('gap', '>', 0)
            ->orderByDesc('gap')
            ->get()
            ->map(fn (SkillAssessment $assessment): array => [
                'id' => (int) $assessment->id,
                'employee' => $employees[(int) $assessment->employee_entity_id]['name'],
                'unitId' => (int) $employees[(int) $assessment->employee_entity_id]['unitId'],
                'skill' => (string) ($skills[$assessment->skill_id] ?? $assessment->requirement_reference),
                'reference' => (string) $assessment->requirement_reference,
                'required' => (int) $assessment->required_level,
                'assessed' => $assessment->assessed_level === null ? null : (int) $assessment->assessed_level,
                'gap' => (int) $assessment->gap,
            ])
            ->all();
    }

    /**
     * @param  array<int, array{id: int, name: string, unitId: ?int, position: ?string}>  $employees
     * @return list<array{id: int, need: string, status: string, employee: string, unitId: int}>
     */
    private function needs(int $companyEntityId, array $employees): array
    {
        if ($employees === []) {
            return [];
        }

        return TrainingRequest::query()
            ->forCompany(app(TenantContext::class)->requireTenantId(), $companyEntityId)
            ->whereIn('requestor_subject_id', array_map(strval(...), array_keys($employees)))
            ->whereIn('status', [
                TrainingRequestStatus::Draft->value,
                TrainingRequestStatus::PendingHod->value,
                TrainingRequestStatus::PendingHr->value,
                TrainingRequestStatus::PendingApproval->value,
            ])
            ->orderBy('id')
            ->get()
            ->map(fn (TrainingRequest $request): array => [
                'id' => (int) $request->id,
                'need' => (string) $request->need,
                'status' => (string) $request->status->value,
                'employee' => $employees[(int) $request->requestor_subject_id]['name'] ?? __('Unknown employee'),
                'unitId' => (int) ($employees[(int) $request->requestor_subject_id]['unitId'] ?? 0),
            ])
            ->all();
    }

    /** @return array{id: int, name: string, unitId: ?int, position: ?string} */
    private function scopedEmployee(int $companyEntityId, string $employeeId): array
    {
        $employees = $this->employees($companyEntityId);

        abort_unless(ctype_digit($employeeId) && array_key_exists((int) $employeeId, $employees), 404);

        return $employees[(int) $employeeId];
    }

    /** @return list<int> */
    private function scopedEmployeeIds(int $companyEntityId): array
    {
        return array_keys($this->employees($companyEntityId));
    }

    private function subject(int $companyEntityId, WorkforceResourceType $type, string $stableId): WorkforceSubject
    {
        return new WorkforceSubject(
            app(TenantContext::class)->requireTenantId(),
            $companyEntityId,
            $type,
            $stableId,
        );
    }

    private function authorizedCompany(): int
    {
        $this->authorizeView();

        abort_unless(
            $this->companyEntityId !== null
            && app(SkillAudience::class)->allowedCompanies(Auth::user(), 'people.skill.assessment.view') !== []
            && app(CompanyAttribution::class)->mayActFor(Auth::user(), $this->companyEntityId),
            404,
        );

        return $this->companyEntityId;
    }

    private function authorizeView(): void
    {
        try {
            app(SkillAudience::class)->authorizeAudience(Auth::user(), 'people.skill.assessment.view');
        } catch (AuthorizationDeniedException) {
            abort(403);
        }
    }

    private function authorizeManage(): void
    {
        try {
            app(SkillAudience::class)->authorizeAudience(Auth::user(), 'people.skill.development-action.manage');
        } catch (AuthorizationDeniedException) {
            abort(403);
        }
    }

    private function authorizeSubmit(): void
    {
        $decision = app(AuthorizationService::class)->can(
            Actor::forUser(Auth::user()),
            TrainingRequestStore::SUBMIT,
        );

        abort_unless($decision->allowed, 403);
    }
}
