<?php

namespace App\Domains\People\Skills\Livewire\Assessment;

use App\Base\Authz\Exceptions\AuthorizationDeniedException;
use App\Base\Tenancy\Contracts\TenantContext;
use App\Domains\People\Skills\Contracts\ResolvesSkillRequirements;
use App\Domains\People\Skills\Data\AssessmentDraft;
use App\Domains\People\Skills\Enums\AssessmentCycle;
use App\Domains\People\Skills\Enums\AssessmentMethod;
use App\Domains\People\Skills\Enums\ProficiencyScaleStatus;
use App\Domains\People\Skills\Exceptions\InvalidAssessmentException;
use App\Domains\People\Skills\Models\ProficiencyScale;
use App\Domains\People\Skills\Models\Skill;
use App\Domains\People\Skills\Models\SkillAssessment;
use App\Domains\People\Skills\Services\AssessmentStore;
use App\Domains\People\Skills\Services\SkillAudience;
use App\Domains\People\Skills\Services\WorkforceSubjects;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Assessment matrix — working surface that atomically submits evidence-backed
 * cells for independent HOD verification. Not a second source of truth.
 */
class Matrix extends Component
{
    public ?int $companyEntityId = null;

    /** @var list<int> */
    public array $selectedSkillIds = [];

    /** @var array<string, string> employeeId:skillId => level string */
    public array $scores = [];

    /** @var array<string, string> employeeId:skillId => evidence */
    public array $evidence = [];

    public string $cycle = 'annual';

    public string $method = 'direct_observation';

    public string $sharedEvidence = '';

    /**
     * Drill-down filters from the HR KPI dashboard (#363): comma-separated
     * latest result bands, and one organisation unit id. Empty means all.
     */
    #[Url]
    public string $band = '';

    #[Url]
    public string $department = '';

    /** @var array<int, string>|null */
    private ?array $allowedCompanies = null;

    public function mount(): void
    {
        $this->authorizeView();
        $companies = $this->allowedCompanies();
        $this->companyEntityId = count($companies) > 0 ? (int) array_key_first($companies) : null;
    }

    public function selectCompany(int $companyEntityId): void
    {
        $this->authorizeView();
        abort_unless(array_key_exists($companyEntityId, $this->allowedCompanies()), 404);
        $this->companyEntityId = $companyEntityId;
        $this->reset('selectedSkillIds', 'scores', 'evidence');
    }

    public function toggleSkill(int $skillId): void
    {
        $this->authorizeAssess();
        if (in_array($skillId, $this->selectedSkillIds, true)) {
            $this->selectedSkillIds = array_values(array_filter(
                $this->selectedSkillIds,
                fn (int $id): bool => $id !== $skillId,
            ));
        } elseif (count($this->selectedSkillIds) < 12) {
            $this->selectedSkillIds[] = $skillId;
        }
    }

    public function saveMatrix(AssessmentStore $store, SkillAudience $audience): void
    {
        $companyEntityId = $this->authorizedCompanyForAssess();

        if ($this->selectedSkillIds === []) {
            $this->addError('matrix', __('Select at least one skill (up to 12).'));

            return;
        }

        $drafts = [];
        $defaultEvidence = trim($this->sharedEvidence);
        $assessedAt = now();

        foreach ($this->employees($companyEntityId) as $employee) {
            foreach ($this->selectedSkillIds as $skillId) {
                $key = $employee->workforce_entity_id.':'.$skillId;
                $levelRaw = trim((string) ($this->scores[$key] ?? ''));
                if ($levelRaw === '') {
                    continue;
                }

                if (! ctype_digit($levelRaw) || (int) $levelRaw > 5) {
                    $this->addError('matrix', __('Scores must be whole numbers from 0 to 5.'));

                    return;
                }

                $cellEvidence = trim((string) ($this->evidence[$key] ?? ''));
                if ($cellEvidence === '') {
                    $cellEvidence = $defaultEvidence;
                }

                $drafts[] = new AssessmentDraft(
                    employeeEntityId: (int) $employee->workforce_entity_id,
                    skillId: (int) $skillId,
                    assessedLevel: (int) $levelRaw,
                    method: AssessmentMethod::from($this->method),
                    cycle: AssessmentCycle::from($this->cycle),
                    assessedAt: $assessedAt,
                    evidence: $cellEvidence,
                    assessorUserId: (int) Auth::id(),
                );
            }
        }

        if ($drafts === []) {
            $this->addError('matrix', __('Enter at least one scored cell with evidence.'));

            return;
        }

        foreach ($drafts as $draft) {
            $audience->authorizeAssessmentSubmission(
                Auth::user(),
                $companyEntityId,
                $draft->employeeEntityId,
            );
        }

        try {
            $submitted = $store->submitBatch(
                Auth::user(),
                $companyEntityId,
                $drafts,
            );
            $store->requestHodVerificationBatch(
                Auth::user(),
                $companyEntityId,
                array_map(static fn (SkillAssessment $assessment): int => (int) $assessment->id, $submitted),
            );
        } catch (InvalidAssessmentException $exception) {
            $this->addError('matrix', $exception->getMessage());

            return;
        }

        $this->reset('scores', 'evidence');
        session()->flash('status', __('Assessment matrix submitted by :name as assessor of record for HOD verification.', [
            'name' => Auth::user()->name,
        ]));
    }

    public function render(): View
    {
        $this->authorizeView();
        $companies = $this->allowedCompanies();
        $companyEntityId = $this->companyEntityId;
        $skills = collect();
        $employees = collect();
        $requiredLevels = [];
        $hasPublishedScale = false;
        $canManageCatalog = false;

        if ($companyEntityId !== null && array_key_exists($companyEntityId, $companies)) {
            $skills = $this->skills($companyEntityId);
            $employees = $this->employees($companyEntityId);
            $requiredLevels = $this->requiredLevels($companyEntityId);
            $hasPublishedScale = ProficiencyScale::query()
                ->forCompany(app(TenantContext::class)->requireTenantId(), $companyEntityId)
                ->where('status', ProficiencyScaleStatus::Published->value)
                ->exists();
            $canManageCatalog = app(SkillAudience::class)->mayManageCatalog(Auth::user(), $companyEntityId);
        }

        return view('people::livewire.assessment.matrix', [
            'companies' => $companies,
            'skills' => $skills,
            'employees' => $employees,
            'requiredLevels' => $requiredLevels,
            'canAssess' => $this->canAssess(),
            'assessorName' => Auth::user()->name,
            'hasPublishedScale' => $hasPublishedScale,
            'canManageCatalog' => $canManageCatalog,
            'selectedSkills' => $skills->whereIn('id', $this->selectedSkillIds)->values(),
        ]);
    }

    private function skills(int $companyEntityId)
    {
        return Skill::query()
            ->forCompany(app(TenantContext::class)->requireTenantId(), $companyEntityId)
            ->where('active', true)
            ->orderBy('code')
            ->get();
    }

    private function employees(int $companyEntityId)
    {
        $employeeEntityIds = app(SkillAudience::class)->visibleEmployeeEntityIds(
            Auth::user(),
            $companyEntityId,
            manage: $this->canAssess(),
        );

        $bandEmployeeIds = $this->employeeIdsWithLatestBand($companyEntityId);

        return collect(app(WorkforceSubjects::class)->employees($companyEntityId))
            ->filter(fn ($employee): bool => in_array((int) $employee->reference->externalId, $employeeEntityIds, true))
            ->filter(fn ($employee): bool => $bandEmployeeIds === null || in_array((int) $employee->reference->externalId, $bandEmployeeIds, true))
            ->filter(fn ($employee): bool => $this->department === ''
                || ($employee->organizationReference !== null && $employee->organizationReference->externalId === $this->department))
            ->map(fn ($employee): object => (object) [
                'workforce_entity_id' => (int) $employee->reference->externalId,
                'display_name' => $employee->displayName,
                'organization_entity_id' => $employee->organizationReference === null
                    ? null : (int) $employee->organizationReference->externalId,
                'position_entity_id' => $employee->positionReference === null
                    ? null : (int) $employee->positionReference->externalId,
            ])
            ->sortBy('display_name')
            ->take(50)
            ->values();
    }

    /**
     * Employees holding a latest (finalized, not superseded) assessment in one
     * of the requested bands, or null when no band filter is set.
     *
     * @return list<int>|null
     */
    private function employeeIdsWithLatestBand(int $companyEntityId): ?array
    {
        $bands = array_values(array_filter(explode(',', $this->band)));

        if ($bands === []) {
            return null;
        }

        $tenantId = app(TenantContext::class)->requireTenantId();

        return SkillAssessment::query()
            ->forCompany($tenantId, $companyEntityId)
            ->where('status', 'finalized')
            ->whereIn('result_band', $bands)
            ->whereNotIn('id', SkillAssessment::query()->forCompany($tenantId, $companyEntityId)
                ->whereNotNull('supersedes_assessment_id')->select('supersedes_assessment_id'))
            ->distinct()
            ->pluck('employee_entity_id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();
    }

    /**
     * Required levels keyed by employeeEntityId:skillId from each employee's
     * workforce projection context (company + department/position).
     *
     * @return array<string, int>
     */
    private function requiredLevels(int $companyEntityId): array
    {
        $resolver = app(ResolvesSkillRequirements::class);
        $levels = [];

        foreach ($this->employees($companyEntityId) as $employee) {
            $context = [
                'company_entity_id' => $companyEntityId,
            ];
            if ($employee->organization_entity_id !== null) {
                $context['department_entity_id'] = (int) $employee->organization_entity_id;
            }
            if ($employee->position_entity_id !== null) {
                $context['position_entity_id'] = (int) $employee->position_entity_id;
            }

            foreach ($resolver->requirementsFor($context) as $requirement) {
                $levels[$employee->workforce_entity_id.':'.$requirement->skillId] = $requirement->requiredLevel;
            }
        }

        return $levels;
    }

    /** @return array<int, string> */
    private function allowedCompanies(): array
    {
        return $this->allowedCompanies ??= app(SkillAudience::class)->allowedCompanies(
            Auth::user(),
            'people.skill.assessment.view',
        );
    }

    private function canAssess(): bool
    {
        try {
            app(SkillAudience::class)->authorizeAudience(
                Auth::user(),
                'people.skill.assessment.manage',
            );

            return true;
        } catch (AuthorizationDeniedException) {
            return false;
        }
    }

    private function authorizeView(): void
    {
        try {
            app(SkillAudience::class)->authorizeAudience(
                Auth::user(),
                'people.skill.assessment.view',
            );
        } catch (AuthorizationDeniedException) {
            abort(403);
        }
    }

    private function authorizeAssess(): void
    {
        try {
            app(SkillAudience::class)->authorizeAudience(
                Auth::user(),
                'people.skill.assessment.manage',
            );
        } catch (AuthorizationDeniedException) {
            abort(403);
        }
    }

    private function authorizedCompanyForAssess(): int
    {
        $this->authorizeAssess();
        abort_unless(
            $this->companyEntityId !== null
            && array_key_exists($this->companyEntityId, $this->allowedCompanies()),
            404,
        );

        abort_if(
            app(SkillAudience::class)->visibleEmployeeEntityIds(
                Auth::user(),
                (int) $this->companyEntityId,
                manage: true,
            ) === [],
            403,
        );

        return (int) $this->companyEntityId;
    }
}
