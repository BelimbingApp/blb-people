<?php

namespace App\Domains\People\Training\Livewire\HrGovernance;

use App\Base\Authz\Exceptions\AuthorizationDeniedException;
use App\Base\Tenancy\Contracts\TenantContext;
use App\Core\Employee\Models\Employee;
use App\Core\User\Models\User;
use App\Domains\People\Performance\Models\PerformanceReviewEscalation;
use App\Domains\People\Provider\Data\WorkforceSubject;
use App\Domains\People\Provider\Enums\WorkforceResourceType;
use App\Domains\People\Skills\Enums\RequirementProfileStatus;
use App\Domains\People\Skills\Exceptions\InvalidReassessmentRequestException;
use App\Domains\People\Skills\Models\RequirementProfile;
use App\Domains\People\Skills\Models\Skill;
use App\Domains\People\Skills\Models\SkillReassessmentRequest;
use App\Domains\People\Skills\Services\RequirementProfileStore;
use App\Domains\People\Skills\Services\SkillAudience;
use App\Domains\People\Skills\Services\SkillReassessmentStore;
use App\Domains\People\Skills\Services\WorkforceSubjects;
use App\Domains\People\Training\Enums\TrainingPlanStatus;
use App\Domains\People\Training\Enums\TrainingRequestStatus;
use App\Domains\People\Training\Exceptions\InvalidTrainingEvidenceSubmissionException;
use App\Domains\People\Training\Exceptions\TrainingPassportDenied;
use App\Domains\People\Training\Models\TrainingEvidenceSubmission;
use App\Domains\People\Training\Models\TrainingParticipant;
use App\Domains\People\Training\Models\TrainingPassportDocument;
use App\Domains\People\Training\Models\TrainingPlan;
use App\Domains\People\Training\Models\TrainingRequest;
use App\Domains\People\Training\Services\TrainingEvidenceSubmissionStore;
use App\Domains\People\Training\Services\TrainingPassportDocumentStore;
use App\Domains\People\Training\Services\TrainingPlanStore;
use App\Domains\People\Training\Services\TrainingRequestStore;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

/**
 * HR governance queue (plan 0005, 0005-f; 0006-c for reassessments):
 * everything awaiting HR in the acting user's company across Skills
 * (requirement publication, reassessment performance) and Training
 * (requests, plan approvals), with the approving actions.
 *
 * The page lists; it never decides. Every action is the owning store's own
 * method with its own capability and company checks, so nothing here can
 * approve what the store would refuse. The company is chosen from the HR
 * user's allowed companies and every query is pinned to it: an item of a
 * sibling company is never listed, whatever its state.
 */
final class Index extends Component
{
    public const VIEW_CAPABILITY = 'people.skill.hr.view';

    public ?int $companyEntityId = null;

    /** @var array<int, string> */
    public array $profileComment = [];

    /** @var array<int, string> */
    public array $requestNotes = [];

    /** @var array<int, int> */
    public array $reassessmentLevels = [];

    /** @var array<int, string> */
    public array $reassessmentDates = [];

    /** @var array<int, string> */
    public array $reassessmentNotes = [];

    /** @var array<int, string> */
    public array $evidenceReturnNotes = [];

    /** @var array<string, string>|null */
    private ?array $allowedCompanies = null;

    public function mount(): void
    {
        $this->authorizeView();
        $companies = $this->allowedCompanies();
        $this->companyEntityId = $companies === [] ? null : (int) array_key_first($companies);
    }

    public function selectCompany(int $companyEntityId): void
    {
        $this->authorizeView();
        abort_unless(array_key_exists($companyEntityId, $this->allowedCompanies()), 404);
        $this->companyEntityId = $companyEntityId;
    }

    public function approveProfile(int $profileId): void
    {
        $companyEntityId = $this->requireCompany();
        $this->denialIs403(function () use ($companyEntityId, $profileId): void {
            app(RequirementProfileStore::class)->approveHr($this->user(), $companyEntityId, $profileId, $this->profileComment[$profileId] ?? '');
            unset($this->profileComment[$profileId]);
        });
    }

    public function returnProfile(int $profileId): void
    {
        $companyEntityId = $this->requireCompany();
        $this->denialIs403(function () use ($companyEntityId, $profileId): void {
            app(RequirementProfileStore::class)->returnByHr($this->user(), $companyEntityId, $profileId, $this->profileComment[$profileId] ?? '');
            unset($this->profileComment[$profileId]);
        });
    }

    public function publishProfile(int $profileId): void
    {
        $companyEntityId = $this->requireCompany();
        $this->denialIs403(function () use ($companyEntityId, $profileId): void {
            app(RequirementProfileStore::class)->publishApproved($this->user(), $companyEntityId, $profileId);
        });
    }

    public function reviewRequest(int $requestId): void
    {
        $companyEntityId = $this->requireCompany();
        $this->denialIs403(function () use ($companyEntityId, $requestId): void {
            app(TrainingRequestStore::class)->review($this->user(), $companyEntityId, $requestId, $this->notes($requestId));
            unset($this->requestNotes[$requestId]);
        });
    }

    public function rejectRequest(int $requestId): void
    {
        $companyEntityId = $this->requireCompany();
        $this->denialIs403(function () use ($companyEntityId, $requestId): void {
            app(TrainingRequestStore::class)->reject($this->user(), $companyEntityId, $requestId, $this->notes($requestId) ?? '');
            unset($this->requestNotes[$requestId]);
        });
    }

    public function approvePlan(int $planId): void
    {
        $companyEntityId = $this->requireCompany();
        $this->denialIs403(function () use ($companyEntityId, $planId): void {
            app(TrainingPlanStore::class)->approve($this->user(), $companyEntityId, $planId);
        });
    }

    public function confirmEvidence(int $submissionId): void
    {
        $companyEntityId = $this->requireCompany();

        try {
            app(TrainingEvidenceSubmissionStore::class)->confirm($this->user(), $companyEntityId, $submissionId);
        } catch (AuthorizationDeniedException) {
            abort(403);
        } catch (InvalidTrainingEvidenceSubmissionException $exception) {
            $this->addError('evidence.'.$submissionId, $exception->getMessage());
        }
    }

    public function returnEvidence(int $submissionId): void
    {
        $companyEntityId = $this->requireCompany();

        try {
            app(TrainingEvidenceSubmissionStore::class)->returnToEmployee(
                $this->user(),
                $companyEntityId,
                $submissionId,
                (string) ($this->evidenceReturnNotes[$submissionId] ?? ''),
            );
            unset($this->evidenceReturnNotes[$submissionId]);
        } catch (AuthorizationDeniedException) {
            abort(403);
        } catch (InvalidTrainingEvidenceSubmissionException $exception) {
            $this->addError('evidence.'.$submissionId, $exception->getMessage());
        }
    }

    public function performReassessment(int $requestId): void
    {
        $companyEntityId = $this->requireCompany();

        try {
            app(SkillReassessmentStore::class)->perform(
                $this->user(),
                $companyEntityId,
                $requestId,
                (int) ($this->reassessmentLevels[$requestId] ?? -1),
                (string) ($this->reassessmentDates[$requestId] ?? ''),
                (string) ($this->reassessmentNotes[$requestId] ?? ''),
            );
            unset(
                $this->reassessmentLevels[$requestId],
                $this->reassessmentDates[$requestId],
                $this->reassessmentNotes[$requestId]
            );
        } catch (AuthorizationDeniedException) {
            abort(403);
        } catch (InvalidReassessmentRequestException $exception) {
            $this->addError('reassessment.'.$requestId, $exception->getMessage());
        }
    }

    /**
     * Generate the printable training passport for an employee listed in the
     * selected company's directory (0014-a). The id must be one this page
     * lists: a request-supplied id outside the company is a 404 before the
     * store is asked, and the store then applies its own HR check.
     */
    public function generatePassportPdf(int $employeeId): void
    {
        $companyEntityId = $this->requireCompany();
        abort_unless(array_key_exists($employeeId, $this->passportEmployees($companyEntityId)), 404);

        $subject = new WorkforceSubject(
            $this->tenantId(),
            $companyEntityId,
            WorkforceResourceType::Employee,
            (string) $employeeId,
        );

        try {
            app(TrainingPassportDocumentStore::class)->generate($this->user(), $subject);
        } catch (TrainingPassportDenied) {
            abort(403);
        }
    }

    public function render(): View
    {
        $this->authorizeView();
        $companies = $this->allowedCompanies();
        // The selected company is a public property, so a client can set it;
        // it is validated against the HR user's companies on every render,
        // not only when chosen through selectCompany().
        $companyEntityId = $this->companyEntityId === null ? null : $this->requireCompany();

        $reassessments = $companyEntityId === null ? collect() : $this->pendingReassessments($companyEntityId);
        $evidence = $companyEntityId === null ? collect() : $this->pendingEvidence($companyEntityId);

        return view('people::livewire.hr-governance.index', [
            'companies' => $companies,
            'profiles' => $companyEntityId === null ? collect() : $this->pendingProfiles($companyEntityId),
            'requests' => $companyEntityId === null ? collect() : $this->pendingRequests($companyEntityId),
            'plans' => $companyEntityId === null ? collect() : $this->pendingPlans($companyEntityId),
            'reassessments' => $reassessments,
            'reassessmentSkills' => $this->reassessmentSkillNames($companyEntityId, $reassessments),
            'reassessmentEmployees' => $this->reassessmentEmployeeNames($companyEntityId, $reassessments),
            'evidenceSubmissions' => $evidence,
            'evidenceEmployees' => $this->evidenceEmployeeNames($companyEntityId, $evidence),
            'escalations' => $companyEntityId === null ? collect() : $this->escalatedReviews($companyEntityId),
            'passportEmployees' => $companyEntityId === null ? [] : $this->passportEmployees($companyEntityId),
            'passportDocuments' => $companyEntityId === null ? [] : $this->passportDocuments($companyEntityId),
        ]);
    }

    /**
     * Employees of the selected company as the directory lists them, keyed by
     * employee entity id: the set an HR user may generate a passport for.
     *
     * @return array<int, string>
     */
    private function passportEmployees(int $companyEntityId): array
    {
        return collect(app(WorkforceSubjects::class)->employees($companyEntityId))
            ->filter(fn ($employee): bool => $employee->active)
            ->sortBy(fn ($employee): string => $employee->displayName)
            ->mapWithKeys(fn ($employee): array => [(int) $employee->reference->externalId => (string) $employee->displayName])
            ->all();
    }

    /**
     * Latest retained passport document per employee of the company.
     *
     * @return array<int, TrainingPassportDocument>
     */
    private function passportDocuments(int $companyEntityId): array
    {
        return TrainingPassportDocument::query()
            ->forCompany($this->tenantId(), $companyEntityId)
            ->whereNotNull('media_asset_id')
            ->unexpired()
            ->orderByDesc('id')
            ->get()
            ->unique('employee_entity_id')
            ->keyBy('employee_entity_id')
            ->all();
    }

    /**
     * Performance reviews escalated past their manager (0009-d).
     *
     * Listed, never acted on here: HR reads that a review has outlasted two
     * weekly reminders. The reviews themselves stay the manager's to finish,
     * which is why this section carries no buttons.
     *
     * @return Collection<int, PerformanceReviewEscalation>
     */
    private function escalatedReviews(int $companyEntityId): Collection
    {
        return PerformanceReviewEscalation::query()
            ->forCompany($this->tenantId(), $companyEntityId)
            ->orderByDesc('notified_at')
            ->orderByDesc('id')
            ->get();
    }

    /**
     * Requirement profiles awaiting HR: pending HR review, or approved and
     * waiting to be published. The store's queue already applies the
     * governance audience; the status filter drops the HOD-stage items.
     *
     * @return Collection<int, RequirementProfile>
     */
    private function pendingProfiles(int $companyEntityId): Collection
    {
        return app(RequirementProfileStore::class)->reviewQueue($this->user(), $companyEntityId)
            ->filter(fn (RequirementProfile $profile): bool => in_array($profile->status, [
                RequirementProfileStatus::PendingHrReview,
                RequirementProfileStatus::Approved,
            ], true))
            ->values();
    }

    /** @return Collection<int, TrainingRequest> */
    private function pendingRequests(int $companyEntityId): Collection
    {
        return TrainingRequest::query()
            ->forCompany($this->tenantId(), $companyEntityId)
            ->where('status', TrainingRequestStatus::PendingHr->value)
            ->orderBy('id')
            ->get();
    }

    /** @return Collection<int, TrainingPlan> */
    private function pendingPlans(int $companyEntityId): Collection
    {
        return TrainingPlan::query()
            ->forCompany($this->tenantId(), $companyEntityId)
            ->where('status', TrainingPlanStatus::Submitted->value)
            ->orderBy('id')
            ->get();
    }

    /**
     * Pending skill reassessments for the HR queue (0006-c). The store's
     * queue already pins the company; a refusal here is a 403, not an
     * empty queue that hides a broken grant.
     *
     * @return Collection<int, SkillReassessmentRequest>
     */
    private function pendingReassessments(int $companyEntityId): Collection
    {
        try {
            return app(SkillReassessmentStore::class)->pendingQueue($this->user(), $companyEntityId);
        } catch (AuthorizationDeniedException) {
            abort(403);
        }
    }

    /** @return array<int, string> */
    private function reassessmentSkillNames(?int $companyEntityId, Collection $reassessments): array
    {
        if ($companyEntityId === null || $reassessments->isEmpty()) {
            return [];
        }

        return Skill::query()
            ->forCompany($this->tenantId(), $companyEntityId)
            ->whereIn('id', $reassessments->pluck('skill_id')->all())
            ->pluck('name', 'id')
            ->map(static fn ($name): string => (string) $name)
            ->all();
    }

    /**
     * Pending evidence submissions for the HR queue (0011-b).
     *
     * @return Collection<int, TrainingEvidenceSubmission>
     */
    private function pendingEvidence(int $companyEntityId): Collection
    {
        try {
            return app(TrainingEvidenceSubmissionStore::class)->pendingQueue($this->user(), $companyEntityId);
        } catch (AuthorizationDeniedException) {
            abort(403);
        }
    }

    /** @return array<int, string> keyed by participant id */
    private function evidenceEmployeeNames(?int $companyEntityId, Collection $submissions): array
    {
        if ($companyEntityId === null || $submissions->isEmpty()) {
            return [];
        }

        $subjects = TrainingParticipant::query()
            ->forCompany($this->tenantId(), $companyEntityId)
            ->whereIn('id', $submissions->pluck('participant_id')->all())
            ->pluck('employee_subject_id', 'id');

        $names = Employee::query()
            ->where('company_id', $companyEntityId)
            ->whereIn('id', $subjects->values()->all())
            ->pluck('full_name', 'id')
            ->map(static fn ($name): string => (string) $name)
            ->all();

        $labeled = [];
        foreach ($subjects as $participantId => $employeeId) {
            $labeled[(int) $participantId] = $names[(int) $employeeId] ?? __('Unknown employee');
        }

        return $labeled;
    }

    /** @return array<int, string> */
    private function reassessmentEmployeeNames(?int $companyEntityId, Collection $reassessments): array
    {
        if ($companyEntityId === null || $reassessments->isEmpty()) {
            return [];
        }

        return Employee::query()
            ->where('company_id', $companyEntityId)
            ->whereIn('id', $reassessments->pluck('employee_entity_id')->all())
            ->pluck('full_name', 'id')
            ->map(static fn ($name): string => (string) $name)
            ->all();
    }

    /**
     * A store's refusal on capability is the answer the page must give too:
     * a 403, not a recovered toast that reads as a transient failure.
     */
    private function denialIs403(\Closure $action): void
    {
        try {
            $action();
        } catch (AuthorizationDeniedException) {
            abort(403);
        }
    }

    private function notes(int $requestId): ?string
    {
        $notes = trim($this->requestNotes[$requestId] ?? '');

        return $notes === '' ? null : $notes;
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
