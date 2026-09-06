<?php

namespace App\Domains\People\Training\Services;

use App\Base\Tenancy\Contracts\TenantContext;
use App\Core\User\Models\User;
use App\Domains\People\Skills\Enums\AssessmentStatus;
use App\Domains\People\Skills\Models\SkillAssessment;
use App\Domains\People\Skills\Services\CompanyAttribution;
use App\Domains\People\Skills\Services\SkillAudience;
use App\Domains\People\Training\Data\EffectivenessOutcomeDraft;
use App\Domains\People\Training\Data\EffectivenessReviewDraft;
use App\Domains\People\Training\Enums\EffectivenessClosureRoute;
use App\Domains\People\Training\Enums\EffectivenessReviewState;
use App\Domains\People\Training\Exceptions\InvalidEffectivenessReviewException;
use App\Domains\People\Training\Models\TrainingEffectivenessReview;
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
            $assessment = SkillAssessment::query()->forCompany($tenantId, $companyEntityId)->find($assessmentId)
                ?? throw new InvalidEffectivenessReviewException('The reassessment was not found in this company.');
            if ($assessment->status !== AssessmentStatus::Finalized) {
                throw new InvalidEffectivenessReviewException(
                    'Closure needs a finalized reassessment; an unverified one is not evidence of competence.',
                );
            }
            $participant = TrainingParticipant::query()->forCompany($tenantId, $companyEntityId)
                ->find($review->training_participant_id)
                ?? throw new InvalidEffectivenessReviewException('The training participant was not found in this company.');
            if ((string) $assessment->employee_entity_id !== (string) $participant->employee_subject_id) {
                throw new InvalidEffectivenessReviewException(
                    'The reassessment belongs to another employee than the reviewed participant.',
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
