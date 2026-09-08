<?php

namespace App\Domains\People\Skills\Services;

use App\Base\Settings\Contracts\SettingsService;
use App\Base\Settings\DTO\Scope;
use App\Base\Tenancy\Contracts\TenantContext;
use App\Core\User\Models\User;
use App\Domains\People\Skills\Data\AssessmentDraft;
use App\Domains\People\Skills\Enums\AssessmentCycle;
use App\Domains\People\Skills\Enums\AssessmentMethod;
use App\Domains\People\Skills\Enums\ReassessmentRequestStatus;
use App\Domains\People\Skills\Exceptions\InvalidReassessmentRequestException;
use App\Domains\People\Skills\Livewire\TeamGaps\Index as TeamGapsIndex;
use App\Domains\People\Skills\Models\EmployeeSkillScore;
use App\Domains\People\Skills\Models\SkillAssessment;
use App\Domains\People\Skills\Models\SkillReassessmentRequest;
use App\Domains\People\Training\Models\TrainingParticipationFact;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * HOD reassessment requests for the reassessment loop (0006-b).
 *
 * The employee always comes from the HOD's visible team set, never from the
 * request: naming an employee outside the department is refused, not
 * recorded. One open request per employee and skill; a resolved or
 * cancelled request does not block the next one, so the rule lives here in
 * a transaction rather than in a unique key.
 *
 * Confirmed training with a pass or certificate opens a request the same
 * way (0006-e): the request carries its source fact, and the score is not
 * touched until the reassessment is performed.
 */
final class SkillReassessmentStore
{
    public const REQUEST = 'people.skill.reassessment.submit';

    public const EXECUTE = 'people.skill.reassessment.execute';

    public const DUE_AFTER_TRAINING_SETTING = 'people-skills.reassessment_after_training_days';

    public const DEFAULT_DUE_AFTER_TRAINING_DAYS = 30;

    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly SkillAudience $audiences,
        private readonly CompanyAttribution $companies,
        private readonly AssessmentStore $assessments,
        private readonly DepartmentHeads $heads,
    ) {}

    public function request(User $actor, int $companyId, int $employeeEntityId, int $skillId, string $reason): SkillReassessmentRequest
    {
        $this->audiences->authorizeAudience($actor, self::REQUEST);
        if (! $this->companies->mayActFor($actor, $companyId)) {
            $this->deny();
        }
        $tenant = $this->tenantContext->requireTenantId();

        $reason = trim($reason);
        if ($reason === '' || mb_strlen($reason) > 1000) {
            throw new InvalidReassessmentRequestException('A reassessment reason of up to 1000 characters is required.');
        }

        return DB::transaction(function () use ($actor, $companyId, $employeeEntityId, $skillId, $reason, $tenant): SkillReassessmentRequest {
            // The same seam the gaps page reads: only a report row the HOD
            // can see may be requested, and the HOD's own row is excluded.
            $visible = $this->audiences->visibleEmployeeEntityIdsFor($actor, $companyId, TeamGapsIndex::VIEW_CAPABILITY, includeSelf: false);
            $visible = array_values(array_filter(
                $visible,
                static fn (int $employeeId): bool => $employeeId !== (int) $actor->employee_id,
            ));
            if (! in_array($employeeEntityId, $visible, true)) {
                $this->deny();
            }

            $open = SkillReassessmentRequest::query()->forCompany($tenant, $companyId)
                ->where('employee_entity_id', $employeeEntityId)
                ->where('skill_id', $skillId)
                ->where('status', ReassessmentRequestStatus::Pending->value)
                ->lockForUpdate()
                ->exists();
            if ($open) {
                throw new InvalidReassessmentRequestException('The employee already has an open reassessment request for this skill.');
            }

            return SkillReassessmentRequest::query()->create([
                'tenant_id' => $tenant, 'company_entity_id' => $companyId,
                'employee_entity_id' => $employeeEntityId, 'skill_id' => $skillId,
                'reason' => $reason, 'requested_by_user_id' => $actor->getKey(),
                'due_at' => today()->addDays(30)->toDateString(),
                'status' => ReassessmentRequestStatus::Pending->value,
                'source' => SkillReassessmentRequest::SOURCE_HOD,
            ]);
        });
    }

    /**
     * Open a reassessment request from a confirmed participation fact
     * (0006-e). The caller has already confirmed the fact under the Training
     * verification capability; this seam only pins the fact to the tenant
     * and company and applies the one-open-request rule. Returns null when
     * the employee already has an open request for the skill, so the caller
     * can count the skip instead of failing the confirmation.
     */
    public function requestFromTraining(
        User $actor,
        int $companyId,
        int $employeeEntityId,
        int $skillId,
        int $factId,
        DateTimeInterface $confirmedAt,
    ): ?SkillReassessmentRequest {
        if (! $this->companies->mayActFor($actor, $companyId)) {
            $this->deny();
        }
        $tenant = $this->tenantContext->requireTenantId();
        $due = CarbonImmutable::instance($confirmedAt)->startOfDay()->addDays($this->dueAfterTrainingDays($tenant));

        return DB::transaction(function () use ($actor, $companyId, $employeeEntityId, $skillId, $factId, $due, $tenant): ?SkillReassessmentRequest {
            $fact = TrainingParticipationFact::query()->forCompany($tenant, $companyId)->whereKey($factId)->first();
            if ($fact === null || $fact->confirmed_at === null) {
                $this->deny();
            }

            $open = SkillReassessmentRequest::query()->forCompany($tenant, $companyId)
                ->where('employee_entity_id', $employeeEntityId)
                ->where('skill_id', $skillId)
                ->where('status', ReassessmentRequestStatus::Pending->value)
                ->lockForUpdate()
                ->exists();
            if ($open) {
                return null;
            }

            return SkillReassessmentRequest::query()->create([
                'tenant_id' => $tenant, 'company_entity_id' => $companyId,
                'employee_entity_id' => $employeeEntityId, 'skill_id' => $skillId,
                'reason' => 'Confirmed training with a pass or certificate.',
                'requested_by_user_id' => $actor->getKey(),
                'due_at' => $due->toDateString(),
                'status' => ReassessmentRequestStatus::Pending->value,
                'source' => SkillReassessmentRequest::SOURCE_TRAINING,
                'source_participation_fact_id' => $fact->id,
            ]);
        });
    }

    /**
     * Days between a confirmed pass or certificate and the reassessment's
     * due date, read at the tenant's scope.
     */
    public function dueAfterTrainingDays(?int $tenantId = null): int
    {
        $tenantId ??= $this->tenantContext->currentTenantId();
        $scope = $tenantId === null ? null : Scope::tenant($tenantId);
        $configured = app(SettingsService::class)->get(self::DUE_AFTER_TRAINING_SETTING, $scope);

        return is_int($configured) && $configured > 0 ? $configured : self::DEFAULT_DUE_AFTER_TRAINING_DAYS;
    }

    /**
     * Pending reassessment requests for one company, oldest first.
     *
     * The company boundary is the listing contract: a sibling company's
     * requests never appear here, and perform() re-checks it per row.
     *
     * @return Collection<int, SkillReassessmentRequest>
     */
    public function pendingQueue(User $actor, int $companyId): Collection
    {
        $this->audiences->authorizeAudience($actor, self::EXECUTE);
        if (! $this->companies->mayActFor($actor, $companyId)) {
            $this->deny();
        }
        $tenant = $this->tenantContext->requireTenantId();

        return SkillReassessmentRequest::query()->forCompany($tenant, $companyId)
            ->where('status', ReassessmentRequestStatus::Pending->value)
            ->orderBy('due_at')->orderBy('id')
            ->get();
    }

    /**
     * Perform a pending reassessment request (0006-c).
     *
     * Walks the assessment lifecycle with the two parties already on the
     * request instead of writing assessment rows directly: HR submits as
     * assessor, the requesting HOD verifies and finalizes, and the
     * finalization repoints the score projection at the new row. History
     * is never rewritten: the previous assessment row is untouched, and a
     * closed request cannot be performed again. Verifier and finalizer are
     * the HOD who opened the request — self-approval of their own request,
     * never another person's decision — while the recorder (HR) stays a
     * different user, so assessment duties stay separated.
     */
    public function perform(
        User $actor,
        int $companyId,
        int $requestId,
        int $newLevel,
        string $assessedAt,
        string $note,
    ): SkillReassessmentRequest {
        $this->audiences->authorizeAudience($actor, self::EXECUTE);
        if (! $this->companies->mayActFor($actor, $companyId)) {
            $this->deny();
        }
        $tenant = $this->tenantContext->requireTenantId();

        if ($newLevel < 0 || $newLevel > 5) {
            throw new InvalidReassessmentRequestException('The reassessed level must be between 0 and 5.');
        }
        try {
            $assessed = CarbonImmutable::parse($assessedAt)->startOfDay();
        } catch (\Throwable) {
            throw new InvalidReassessmentRequestException('The assessment date is not a valid date.');
        }
        if ($assessed->greaterThan(CarbonImmutable::today())) {
            throw new InvalidReassessmentRequestException('The assessment date cannot be in the future.');
        }
        $note = trim($note);
        if ($note === '' || mb_strlen($note) > 1000) {
            throw new InvalidReassessmentRequestException('An assessor note of up to 1000 characters is required.');
        }

        return DB::transaction(function () use ($actor, $companyId, $requestId, $newLevel, $assessed, $note, $tenant): SkillReassessmentRequest {
            $request = SkillReassessmentRequest::query()->forCompany($tenant, $companyId)
                ->whereKey($requestId)
                ->lockForUpdate()
                ->first();
            if ($request === null || ! $request->isOpen()) {
                throw new InvalidReassessmentRequestException('The reassessment request is no longer open.');
            }

            $score = EmployeeSkillScore::query()->forCompany($tenant, $companyId)
                ->where('employee_entity_id', $request->employee_entity_id)
                ->where('skill_id', $request->skill_id)
                ->lockForUpdate()
                ->first();
            if ($score === null) {
                throw new InvalidReassessmentRequestException('The employee has no released score for this skill.');
            }

            $verifier = User::query()->findOrFail($this->verifierUserId($companyId, $request));

            $previous = SkillAssessment::query()->forCompany($tenant, $companyId)
                ->whereKey((int) $score->source_assessment_id)
                ->firstOrFail();

            $submitted = $this->assessments->submit(
                $actor,
                $companyId,
                new AssessmentDraft(
                    employeeEntityId: (int) $request->employee_entity_id,
                    skillId: (int) $request->skill_id,
                    assessedLevel: $newLevel,
                    method: $previous->method ?? AssessmentMethod::DirectObservation,
                    cycle: $previous->cycle ?? AssessmentCycle::Annual,
                    assessedAt: $assessed,
                    evidence: $note,
                    notes: 'Reassessment performed for request #'.$request->id.'.',
                ),
                supersedesAssessmentId: (int) $previous->id,
            );
            $this->assessments->requestHodVerification($actor, $companyId, (int) $submitted->id);
            $this->assessments->verifyHod(
                $verifier, $companyId, (int) $submitted->id,
                'Verified against reassessment request #'.$request->id.'.',
            );
            $this->assessments->finalizeVerified($verifier, $companyId, (int) $submitted->id);

            $request->update([
                'status' => ReassessmentRequestStatus::Resolved->value,
                'performed_by_user_id' => $actor->getKey(),
                'performed_at' => now(),
                'outcome' => $note,
            ]);

            return $request->refresh();
        });
    }

    /**
     * Who verifies the reassessment. A HOD-sourced request is verified by
     * the HOD who opened it; a training-sourced request was opened by the
     * HR confirmer, so the employee's current department head verifies —
     * never the HR recorder.
     */
    private function verifierUserId(int $companyId, SkillReassessmentRequest $request): int
    {
        if (! $request->isFromTraining()) {
            return (int) $request->requested_by_user_id;
        }
        $head = $this->heads->headUserOf($companyId, (int) $request->employee_entity_id);
        if ($head === null) {
            throw new InvalidReassessmentRequestException('The employee has no department head to verify the reassessment.');
        }

        return $head;
    }

    private function deny(): never
    {
        throw new InvalidReassessmentRequestException('The reassessment request is unavailable in the current scope.');
    }
}
