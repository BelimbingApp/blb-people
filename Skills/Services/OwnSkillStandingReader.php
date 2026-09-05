<?php

namespace App\Domains\People\Skills\Services;

use App\Base\Authz\Exceptions\AuthorizationDeniedException;
use App\Base\Tenancy\Contracts\TenantContext;
use App\Core\User\Models\User;
use App\Domains\People\Provider\Contracts\ReadsWorkforceDirectory;
use App\Domains\People\Provider\Data\WorkforceEmployee;
use App\Domains\People\Provider\Data\WorkforceSubject;
use App\Domains\People\Provider\Enums\WorkforceResourceType;
use App\Domains\People\Skills\Contracts\ReadsOwnSkillStanding;
use App\Domains\People\Skills\Data\OwnAssessmentOutcome;
use App\Domains\People\Skills\Data\OwnSkillStanding;
use App\Domains\People\Skills\Enums\AssessmentStatus;
use App\Domains\People\Skills\Enums\SelfStandingRefusal;
use App\Domains\People\Skills\Exceptions\SelfStandingDenied;
use App\Domains\People\Skills\Models\EmployeeSkillScore;
use App\Domains\People\Skills\Models\SkillActorBinding;
use App\Domains\People\Skills\Models\SkillAssessment;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Throwable;

final class OwnSkillStandingReader implements ReadsOwnSkillStanding
{
    private const array PUBLIC_COLUMNS = [
        'id', 'skill_id', 'requirement_reference', 'requirement_version', 'required_level',
        'assessed_level', 'gap', 'result_band', 'assessed_at', 'finalized_at',
        'valid_until', 'next_assessment_due', 'status',
    ];

    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly ReadsWorkforceDirectory $directory,
        private readonly SkillAudience $audience,
    ) {}

    public function read(?User $actor, WorkforceSubject $subject, ?DateTimeInterface $asOf = null): OwnSkillStanding
    {
        $employee = $this->authorize($actor, $subject);
        // Current attribution cannot prove historical employment/permission scope.
        if ($asOf !== null) {
            throw new SelfStandingDenied(SelfStandingRefusal::UnsupportedPeriod);
        }
        $cutoff = now()->toImmutable();
        $assessments = $this->assessments($subject)
            ->where('status', AssessmentStatus::Finalized->value)
            ->whereNotNull('finalized_at')
            ->where('finalized_at', '<=', $cutoff)
            ->orderByDesc('assessed_at')->orderByDesc('id')
            ->get(self::PUBLIC_COLUMNS)
            ->map(fn (SkillAssessment $row): OwnAssessmentOutcome => $this->outcome($row))
            ->keyBy('assessmentId');
        $sources = EmployeeSkillScore::query()
            ->forCompany($subject->tenantId, $subject->companyId)
            ->where('employee_entity_id', (int) $subject->stableId)
            ->orderBy('skill_id')
            ->pluck('source_assessment_id');
        $standing = [];
        foreach ($sources as $source) {
            if (! $assessments->has((int) $source)) {
                throw new SelfStandingDenied(SelfStandingRefusal::Unpublished);
            }
            $standing[] = $assessments->get((int) $source);
        }

        return new OwnSkillStanding($subject, $cutoff, $employee->observedAt, $standing, $assessments->values()->all());
    }

    public function assessment(?User $actor, WorkforceSubject $subject, int $assessmentId): OwnAssessmentOutcome
    {
        $this->authorize($actor, $subject);
        $assessment = $this->assessments($subject)->find($assessmentId, self::PUBLIC_COLUMNS);
        if ($assessment === null) {
            throw new SelfStandingDenied(SelfStandingRefusal::Unavailable);
        }
        if ($assessment->status !== AssessmentStatus::Finalized || $assessment->finalized_at === null || $assessment->finalized_at->isFuture()) {
            throw new SelfStandingDenied(SelfStandingRefusal::Unpublished);
        }

        return $this->outcome($assessment);
    }

    private function authorize(?User $actor, WorkforceSubject $subject): WorkforceEmployee
    {
        $tenant = $this->tenantContext->currentTenantId();
        if ($tenant === null || $subject->tenantId !== $tenant || $subject->companyId === null) {
            throw new SelfStandingDenied(SelfStandingRefusal::MissingScope);
        }
        if ($actor === null || ! $actor->exists || $actor->tenant_id !== $tenant) {
            throw new SelfStandingDenied(SelfStandingRefusal::Unauthorized);
        }
        try {
            $audiences = $this->audience->authorizeAudience($actor, 'people.skill.assessment.view');
        } catch (AuthorizationDeniedException) {
            throw new SelfStandingDenied(SelfStandingRefusal::Unauthorized);
        }
        if (! in_array(SkillAudience::EMPLOYEE, $audiences, true)) {
            throw new SelfStandingDenied(SelfStandingRefusal::Unauthorized);
        }
        try {
            $company = $actor->company_id === null ? null : $this->directory->companyForPlatform((int) $actor->company_id);
            $employee = $this->directory->employeeForUser((string) $subject->companyId, (int) $actor->id);
        } catch (Throwable) {
            throw new SelfStandingDenied(SelfStandingRefusal::Unavailable);
        }
        if ($company === null || ! $company->active || $company->reference->externalId !== (string) $subject->companyId) {
            throw new SelfStandingDenied(SelfStandingRefusal::SubjectMismatch);
        }
        if ($employee === null || ! $employee->active || $employee->userReferenceRevoked) {
            throw new SelfStandingDenied(SelfStandingRefusal::BindingUnavailable);
        }
        if ($subject->type !== WorkforceResourceType::Employee
            || ! ctype_digit($subject->stableId)
            || $employee->reference->externalId !== $subject->stableId
            || $employee->companyReference != $company->reference
            || ($subject->externalReference !== null && $subject->externalReference != $employee->reference)) {
            throw new SelfStandingDenied(SelfStandingRefusal::SubjectMismatch);
        }
        $bindings = SkillActorBinding::query()
            ->forCompany($tenant, $subject->companyId)
            ->where('platform_user_id', $actor->id)
            ->where('employee_entity_id', (int) $subject->stableId)
            ->whereNotNull('confirmed_at')
            ->whereNull('revoked_at')
            ->limit(2)->get();
        if ($bindings->count() !== 1) {
            throw new SelfStandingDenied(SelfStandingRefusal::BindingUnavailable);
        }

        return $employee;
    }

    private function assessments(WorkforceSubject $subject): Builder
    {
        return SkillAssessment::query()
            ->forCompany($subject->tenantId, $subject->companyId)
            ->where('employee_entity_id', (int) $subject->stableId);
    }

    private function outcome(SkillAssessment $row): OwnAssessmentOutcome
    {
        return new OwnAssessmentOutcome(
            (int) $row->id, (int) $row->skill_id, $row->requirement_reference,
            $row->requirement_version, $row->required_level, $row->assessed_level, $row->gap,
            $row->result_band?->value, $row->assessed_at?->toIso8601String(),
            $row->finalized_at->toIso8601String(), $row->valid_until?->toDateString(),
            $row->next_assessment_due?->toDateString(),
        );
    }
}
