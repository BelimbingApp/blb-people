<?php

namespace App\Domains\People\Training\Services;

use App\Base\Tenancy\Contracts\TenantContext;
use App\Core\User\Models\User;
use App\Domains\People\Provider\Contracts\ReadsWorkforceDirectory;
use App\Domains\People\Skills\Data\DevelopmentActionDraft;
use App\Domains\People\Skills\Enums\AssessmentStatus;
use App\Domains\People\Skills\Models\DevelopmentAction;
use App\Domains\People\Skills\Models\SkillAssessment;
use App\Domains\People\Skills\Services\CompanyAttribution;
use App\Domains\People\Skills\Services\DevelopmentActionStore;
use App\Domains\People\Skills\Services\SkillAudience;
use App\Domains\People\Training\Data\EffectivenessOutcomeDraft;
use App\Domains\People\Training\Data\EffectivenessReviewDraft;
use App\Domains\People\Training\Enums\CutoverWorkflow;
use App\Domains\People\Training\Enums\EffectivenessClosureRoute;
use App\Domains\People\Training\Enums\EffectivenessOutcome;
use App\Domains\People\Training\Enums\EffectivenessReviewState;
use App\Domains\People\Training\Exceptions\InvalidEffectivenessReviewException;
use App\Domains\People\Training\Models\TrainingCourse;
use App\Domains\People\Training\Models\TrainingEffectivenessReview;
use App\Domains\People\Training\Models\TrainingEvent;
use App\Domains\People\Training\Models\TrainingParticipant;
use Illuminate\Support\Facades\DB;

/**
 * The training effectiveness review record: whether learning transferred to
 * workplace results.
 *
 * Three facts stay separate here, because the contract insists on it. A
 * recorded outcome is not permission to close. Workplace ratings never become
 * a competence score. And the post-training level comes from the official
 * Skills reassessment or from nowhere — never from a number somebody typed
 * into this review.
 */
final class TrainingEffectivenessStore
{
    /** Opening a stage and recording its outcome: the accountable HOD. */
    public const REVIEW_CAPABILITY = 'people.training.effectiveness.review';

    /** Controlled closure, either route: HR. */
    public const CLOSE_CAPABILITY = 'people.training.effectiveness.close';

    public function __construct(
        private readonly TenantContext $tenancy,
        private readonly CompanyAttribution $companies,
        private readonly SkillAudience $audiences,
        private readonly ReadsWorkforceDirectory $directory,
        private readonly DevelopmentActionStore $developmentActions,
        private readonly CutoverWriteGuard $cutover,
    ) {}

    public function openStage(User $actor, int $companyEntityId, EffectivenessReviewDraft $draft): TrainingEffectivenessReview
    {
        $tenantId = $this->scope($actor, $companyEntityId);
        $this->authorize($actor, SkillAudience::HOD, self::REVIEW_CAPABILITY,
            'Only a HOD may review training effectiveness.');

        if (trim($draft->dueDatePolicy) === '') {
            throw new InvalidEffectivenessReviewException(
                'A due date must name the governed policy that chose it; the review does not infer an anchor.',
            );
        }
        $participant = TrainingParticipant::query()->forCompany($tenantId, $companyEntityId)
            ->find($draft->participantId)
            ?? throw new InvalidEffectivenessReviewException('The training participant was not found in this company.');
        $this->assertReviewerIsNotParticipant($draft->reviewerEmployeeEntityId, $participant);
        $this->assertActorIsNotParticipant($actor, $companyEntityId, $participant);
        $this->assertReviewerBelongsToCompany($companyEntityId, $draft->reviewerEmployeeEntityId);

        return TrainingEffectivenessReview::query()->create([
            'tenant_id' => $tenantId,
            'company_entity_id' => $companyEntityId,
            'training_participant_id' => $participant->id,
            'stage' => $draft->stage,
            'due_on' => $draft->dueOn,
            'due_date_policy' => trim($draft->dueDatePolicy),
            'reviewer_employee_entity_id' => $draft->reviewerEmployeeEntityId,
            'baseline_level' => $draft->baselineLevel,
            'target_level' => $draft->targetLevel,
            'requirement_reference' => $draft->requirementReference,
            'requirement_version' => $draft->requirementVersion,
            'state' => EffectivenessReviewState::Open,
        ]);
    }

    public function recordOutcome(
        User $actor,
        int $companyEntityId,
        int $reviewId,
        EffectivenessOutcomeDraft $draft,
    ): TrainingEffectivenessReview {
        $tenantId = $this->scope($actor, $companyEntityId);
        $this->authorize($actor, SkillAudience::HOD, self::REVIEW_CAPABILITY,
            'Only a HOD may review training effectiveness.');

        foreach ([$draft->applicationRating, $draft->improvementRating, $draft->impactRating] as $rating) {
            if ($rating < 1 || $rating > 5) {
                throw new InvalidEffectivenessReviewException('Each workplace rating must be between 1 and 5.');
            }
        }
        if (trim($draft->evidence) === '') {
            throw new InvalidEffectivenessReviewException('An outcome needs attributable workplace evidence.');
        }

        return DB::transaction(function () use ($tenantId, $companyEntityId, $reviewId, $draft, $actor): TrainingEffectivenessReview {
            $review = $this->find($tenantId, $companyEntityId, $reviewId);
            if ($review->state === EffectivenessReviewState::Closed) {
                throw new InvalidEffectivenessReviewException(
                    'A closed review is a historical fact; record another occurrence of the stage instead.',
                );
            }
            $this->assertActorIsNotParticipant($actor, $companyEntityId,
                $this->participant($tenantId, $companyEntityId, (int) $review->training_participant_id));
            $review->update([
                'outcome' => $draft->outcome,
                'application_rating' => $draft->applicationRating,
                'improvement_rating' => $draft->improvementRating,
                'impact_rating' => $draft->impactRating,
                'evidence' => trim($draft->evidence),
                'further_action' => $this->trimNullable($draft->furtherAction),
                'reviewed_on' => $draft->reviewedOn,
                'outcome_recorded_at' => now(),
                'outcome_recorded_by_user_id' => $actor->getKey(),
                'state' => EffectivenessReviewState::OutcomeRecorded,
            ]);

            return $review->refresh();
        });
    }

    /**
     * The development action a review that did not find the training effective
     * owes somebody (0013-f).
     *
     * `further_action` is free text: it can say "coach him again next month"
     * and nobody is named, nothing is due and no reassessment is scheduled.
     * This links the review to a Skills development action, which carries all
     * three, so the closure rule in the contract has something to point at.
     *
     * The action's subject is never the caller's to choose. The employee comes
     * from the reviewed participant, the levels from the review, and the skill
     * from the skills the participant's own course covers — a follow-up that
     * could address any skill at all would let a HOD close a failed isolation
     * course with a spreadsheet action.
     *
     * Passing an int links an already-open action of that same employee and a
     * course skill, for the case where the gap is already being worked.
     */
    public function openFollowUpAction(
        User $actor,
        int $companyEntityId,
        int $reviewId,
        DevelopmentActionDraft|int $action,
    ): TrainingEffectivenessReview {
        $tenantId = $this->scope($actor, $companyEntityId);
        $this->authorize($actor, SkillAudience::HOD, self::REVIEW_CAPABILITY,
            'Only a HOD may open a follow-up development action.');

        return DB::transaction(function () use ($tenantId, $companyEntityId, $reviewId, $action, $actor): TrainingEffectivenessReview {
            $review = $this->find($tenantId, $companyEntityId, $reviewId);
            $this->assertOwesFollowUp($review);
            $participant = $this->participant($tenantId, $companyEntityId, (int) $review->training_participant_id);
            $this->assertActorIsNotParticipant($actor, $companyEntityId, $participant);

            // The participant's subject id is the selected provider's stable
            // id; for the native provider it is the platform employee id, and
            // DevelopmentActionStore refuses any id that is not an active
            // employee of this company, so a mismatch is refused, not assumed.
            $employeeEntityId = (int) $participant->employee_subject_id;
            $courseSkillIds = $this->courseSkillIds($tenantId, $companyEntityId, $participant);

            $linked = is_int($action)
                ? $this->existingFollowUpAction($tenantId, $companyEntityId, $action, $employeeEntityId, $courseSkillIds)
                : $this->newFollowUpAction($companyEntityId, $review, $action, $employeeEntityId, $courseSkillIds, $actor);

            $review->update(['development_action_id' => $linked->getKey()]);

            return $review->refresh();
        });
    }

    private function assertOwesFollowUp(TrainingEffectivenessReview $review): void
    {
        if ($review->state === EffectivenessReviewState::Closed) {
            throw new InvalidEffectivenessReviewException(
                'A closed review is a historical fact; open the follow-up before closing it.',
            );
        }
        if (! in_array($review->outcome, [
            EffectivenessOutcome::PartiallyEffective,
            EffectivenessOutcome::NotYetEffective,
        ], true)) {
            throw new InvalidEffectivenessReviewException(
                'Only a partially or not-yet-effective outcome owes a follow-up development action.',
            );
        }
        if ($review->development_action_id !== null) {
            throw new InvalidEffectivenessReviewException(
                'This review already carries a follow-up action; revise that action rather than opening a second.',
            );
        }
    }

    /**
     * The skills the reviewed participant's own course covers.
     *
     * @return list<int>
     */
    private function courseSkillIds(int $tenantId, int $companyEntityId, TrainingParticipant $participant): array
    {
        $event = TrainingEvent::query()->forCompany($tenantId, $companyEntityId)
            ->find($participant->event_id)
            ?? throw new InvalidEffectivenessReviewException('The training event was not found in this company.');
        $course = TrainingCourse::query()->forCompany($tenantId, $companyEntityId)
            ->find($event->course_id)
            ?? throw new InvalidEffectivenessReviewException('The training course was not found in this company.');

        return $course->skillIds();
    }

    /** @param list<int> $courseSkillIds */
    private function newFollowUpAction(
        int $companyEntityId,
        TrainingEffectivenessReview $review,
        DevelopmentActionDraft $draft,
        int $employeeEntityId,
        array $courseSkillIds,
        User $actor,
    ): DevelopmentAction {
        $skillId = $draft->skillId ?? (count($courseSkillIds) === 1 ? $courseSkillIds[0] : null);
        if ($skillId === null) {
            throw new InvalidEffectivenessReviewException(
                'The reviewed course covers more than one skill; name the one this follow-up addresses.',
            );
        }
        if (! in_array($skillId, $courseSkillIds, true)) {
            throw new InvalidEffectivenessReviewException(
                'The follow-up addresses a skill the reviewed course does not cover.',
            );
        }
        // Where the employee actually stands: the verified post-training level
        // when the review has one, and otherwise the baseline it opened with.
        $startingLevel = $review->post_level ?? $review->baseline_level;
        if ($startingLevel === null || $review->target_level === null) {
            throw new InvalidEffectivenessReviewException(
                'The review records no level to start from or to aim at, and an action needs both.',
            );
        }
        if ($draft->criticality === null) {
            throw new InvalidEffectivenessReviewException(
                'A follow-up action needs the criticality of the requirement it closes.',
            );
        }

        return $this->developmentActions->proposeManual($companyEntityId, new DevelopmentActionDraft(
            employeeEntityId: $employeeEntityId,
            type: $draft->type,
            objective: $draft->objective,
            intervention: $draft->intervention,
            expectedEvidence: $draft->expectedEvidence,
            ownerEmployeeEntityId: $draft->ownerEmployeeEntityId,
            hrCoordinatorEmployeeEntityId: $draft->hrCoordinatorEmployeeEntityId,
            startDate: $draft->startDate,
            dueDate: $draft->dueDate,
            trainerEmployeeEntityId: $draft->trainerEmployeeEntityId,
            trainerProviderName: $draft->trainerProviderName,
            skillId: $skillId,
            startingLevel: (int) $startingLevel,
            targetLevel: (int) $review->target_level,
            criticality: $draft->criticality,
            mandatoryGate: $draft->mandatoryGate,
            nextSteps: $draft->nextSteps,
            trainingCourseCode: $draft->trainingCourseCode,
            manualReason: sprintf(
                'Follow-up from the %s training effectiveness review #%d, outcome %s.',
                $review->stage->label(),
                (int) $review->getKey(),
                $review->outcome->label(),
            ),
        ), (int) $actor->getKey());
    }

    /** @param list<int> $courseSkillIds */
    private function existingFollowUpAction(
        int $tenantId,
        int $companyEntityId,
        int $actionId,
        int $employeeEntityId,
        array $courseSkillIds,
    ): DevelopmentAction {
        $action = DevelopmentAction::query()->forCompany($tenantId, $companyEntityId)->find($actionId)
            ?? throw new InvalidEffectivenessReviewException('The development action was not found in this company.');
        if ((int) $action->employee_entity_id !== $employeeEntityId) {
            throw new InvalidEffectivenessReviewException(
                'The development action belongs to another employee than the reviewed participant.',
            );
        }
        if (! in_array((int) $action->skill_id, $courseSkillIds, true)) {
            throw new InvalidEffectivenessReviewException(
                'The development action addresses a skill the reviewed course does not cover.',
            );
        }
        if (! $action->status->isOpen()) {
            throw new InvalidEffectivenessReviewException(
                'Link an open development action; a completed or cancelled one cannot carry new follow-up.',
            );
        }

        return $action;
    }

    public function closeWithReassessment(
        User $actor,
        int $companyEntityId,
        int $reviewId,
        int $assessmentId,
    ): TrainingEffectivenessReview {
        $tenantId = $this->scope($actor, $companyEntityId);
        $this->authorize($actor, SkillAudience::HR, self::CLOSE_CAPABILITY,
            'Only HR may close a training effectiveness review.');

        return DB::transaction(function () use ($tenantId, $companyEntityId, $reviewId, $assessmentId, $actor): TrainingEffectivenessReview {
            $review = $this->closable($tenantId, $companyEntityId, $reviewId);
            $participant = $this->participant($tenantId, $companyEntityId, (int) $review->training_participant_id);
            $this->assertActorIsNotParticipant($actor, $companyEntityId, $participant);
            $assessment = SkillAssessment::query()->forCompany($tenantId, $companyEntityId)->find($assessmentId)
                ?? throw new InvalidEffectivenessReviewException('The reassessment was not found in this company.');
            if ($assessment->status !== AssessmentStatus::Finalized) {
                throw new InvalidEffectivenessReviewException(
                    'Closure needs a finalized reassessment; an unverified one is not evidence of competence.',
                );
            }
            if ((string) $assessment->employee_entity_id !== (string) $participant->employee_subject_id) {
                throw new InvalidEffectivenessReviewException(
                    'The reassessment belongs to another employee than the reviewed participant.',
                );
            }
            if ($assessment->assessor_employee_entity_id !== null
                && (int) $assessment->assessor_employee_entity_id === (int) $review->reviewer_employee_entity_id) {
                throw new InvalidEffectivenessReviewException(
                    'The reassessment was made by this review\'s own reviewer; closure needs the assessor/HOD separation.',
                );
            }

            $review->update([
                'state' => EffectivenessReviewState::Closed,
                'closure_route' => EffectivenessClosureRoute::Reassessment,
                'post_level' => $assessment->assessed_level,
                'reassessment_assessment_id' => $assessment->id,
                'reassessment_requirement_reference' => $assessment->requirement_reference,
                'reassessment_requirement_version' => $assessment->requirement_version,
                'closed_at' => now(),
                'closed_by_user_id' => $actor->getKey(),
            ]);

            return $review->refresh();
        });
    }

    public function closeAsNonAssessable(
        User $actor,
        int $companyEntityId,
        int $reviewId,
        string $reason,
    ): TrainingEffectivenessReview {
        $tenantId = $this->scope($actor, $companyEntityId);
        $this->authorize($actor, SkillAudience::HR, self::CLOSE_CAPABILITY,
            'Only HR may close a training effectiveness review.');
        if (trim($reason) === '') {
            throw new InvalidEffectivenessReviewException(
                'A non-assessable closure needs the approved reason and decision-maker on the record.',
            );
        }

        return DB::transaction(function () use ($tenantId, $companyEntityId, $reviewId, $reason, $actor): TrainingEffectivenessReview {
            $review = $this->closable($tenantId, $companyEntityId, $reviewId);
            $this->assertActorIsNotParticipant($actor, $companyEntityId,
                $this->participant($tenantId, $companyEntityId, (int) $review->training_participant_id));
            $review->update([
                'state' => EffectivenessReviewState::Closed,
                'closure_route' => EffectivenessClosureRoute::NonAssessable,
                'closure_reason' => trim($reason),
                'closed_at' => now(),
                'closed_by_user_id' => $actor->getKey(),
            ]);

            return $review->refresh();
        });
    }

    /**
     * Conflicts of interest. A review that a person can steer onto themselves
     * is not a control, so the three refusals below are enforced here and
     * mirrored by a database guard on the reviewer/participant pair.
     */
    private function assertReviewerIsNotParticipant(int $reviewerEmployeeEntityId, TrainingParticipant $participant): void
    {
        if ((string) $reviewerEmployeeEntityId === (string) $participant->employee_subject_id) {
            throw new InvalidEffectivenessReviewException(
                'A reviewer cannot be the participant under review; name an independent reviewer.',
            );
        }
    }

    /**
     * The acting user's own projected employee, resolved through the workforce
     * seam rather than through `users.employee_id`: the seam is what decides
     * whether a portal link is active in this company at all.
     *
     * Only the subject id is compared, not the participant's `provider_id`.
     * Requiring both would make this control silently inert for any
     * participant row written under a different provider label, and a
     * conflict-of-interest guard that quietly stops firing is worse than one
     * that refuses a same-id participant of another provider. It is also the
     * comparison the reviewer rule and the database guard already make.
     */
    private function assertActorIsNotParticipant(User $actor, int $companyEntityId, TrainingParticipant $participant): void
    {
        $employee = $this->directory->employeeForUser((string) $companyEntityId, (int) $actor->getKey());
        if ($employee === null) {
            return;
        }
        if ($employee->reference->externalId === (string) $participant->employee_subject_id) {
            throw new InvalidEffectivenessReviewException(
                'You cannot act on your own training effectiveness review; an independent reviewer must.',
            );
        }
    }

    private function assertReviewerBelongsToCompany(int $companyEntityId, int $reviewerEmployeeEntityId): void
    {
        foreach ($this->directory->employees((string) $companyEntityId) as $employee) {
            if ($employee->reference->externalId === (string) $reviewerEmployeeEntityId) {
                return;
            }
        }

        throw new InvalidEffectivenessReviewException('Choose an active reviewer from this company.');
    }

    private function participant(int $tenantId, int $companyEntityId, int $participantId): TrainingParticipant
    {
        return TrainingParticipant::query()->forCompany($tenantId, $companyEntityId)->find($participantId)
            ?? throw new InvalidEffectivenessReviewException('The training participant was not found in this company.');
    }

    private function closable(int $tenantId, int $companyEntityId, int $reviewId): TrainingEffectivenessReview
    {
        $review = $this->find($tenantId, $companyEntityId, $reviewId);
        if ($review->state === EffectivenessReviewState::Closed) {
            throw new InvalidEffectivenessReviewException('The review is already closed.');
        }
        if ($review->state !== EffectivenessReviewState::OutcomeRecorded) {
            throw new InvalidEffectivenessReviewException(
                'Record the reviewed outcome before closing; closure is a separate decision.',
            );
        }

        return $review;
    }

    private function find(int $tenantId, int $companyEntityId, int $reviewId): TrainingEffectivenessReview
    {
        return TrainingEffectivenessReview::query()->forCompany($tenantId, $companyEntityId)
            ->lockForUpdate()->find($reviewId)
            ?? throw new InvalidEffectivenessReviewException('The effectiveness review was not found in this company.');
    }

    private function scope(User $actor, int $companyEntityId): int
    {
        $tenantId = $this->tenancy->currentTenantId();
        if ($tenantId === null) {
            throw new InvalidEffectivenessReviewException('A tenant context is required for effectiveness reviews.');
        }
        if ((int) $actor->tenant_id !== $tenantId || ! $this->companies->mayActFor($actor, $companyEntityId)) {
            throw new InvalidEffectivenessReviewException(
                'The effectiveness review is unavailable in the current company scope.',
            );
        }
        // Single choke point: openStage and every later write enter here first.
        $this->cutover->assertWritable($companyEntityId, CutoverWorkflow::Effectiveness);

        return $tenantId;
    }

    private function authorize(User $actor, string $audience, string $capability, string $message): void
    {
        try {
            $audiences = $this->audiences->authorizeAudience($actor, $capability);
        } catch (\Throwable) {
            throw new InvalidEffectivenessReviewException($message);
        }
        if (! in_array($audience, $audiences, true)) {
            throw new InvalidEffectivenessReviewException($message);
        }
    }

    private function trimNullable(?string $value): ?string
    {
        $value = $value === null ? null : trim($value);

        return $value === '' ? null : $value;
    }
}
