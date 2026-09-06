<?php

namespace App\Domains\People\Employees\Services;

use App\Core\User\Models\User;
use App\Domains\People\Employees\Data\EmployeeStanding;
use App\Domains\People\Employees\Data\OwnEffectivenessOutcome;
use App\Domains\People\Provider\Data\WorkforceSubject;
use App\Domains\People\Skills\Contracts\ReadsOwnSkillStanding;
use App\Domains\People\Skills\Data\OwnAssessmentOutcome;
use App\Domains\People\Training\Models\TrainingEffectivenessReview;
use App\Domains\People\Training\Models\TrainingParticipant;
use DateTimeInterface;

final class EmployeeStandingReader
{
    public function __construct(private readonly ReadsOwnSkillStanding $skills) {}

    public function read(?User $actor, WorkforceSubject $subject, ?DateTimeInterface $asOf = null): EmployeeStanding
    {
        $skills = $this->skills->read($actor, $subject, $asOf);

        return new EmployeeStanding($skills, $this->effectivenessOutcomes($skills->subject));
    }

    public function assessment(?User $actor, WorkforceSubject $subject, int $assessmentId): OwnAssessmentOutcome
    {
        return $this->skills->assessment($actor, $subject, $assessmentId);
    }

    /**
     * The authorized subject's own effectiveness outcomes per stage.
     *
     * Authorization is the skills self-binding check in read(): it throws for
     * any other subject, so rows are additionally scoped to the authorized
     * subject's own participant records in the same tenant/company. Columns
     * are allow-listed at the query — reviewer identity, evidence, follow-up,
     * closure reasons, ratings and levels never leave the database — rather
     * than hidden after loading.
     *
     * @return list<OwnEffectivenessOutcome>
     */
    private function effectivenessOutcomes(WorkforceSubject $subject): array
    {
        // Unreachable after a successful read, which refuses missing scope;
        // fail closed rather than querying unscoped.
        if ($subject->tenantId === null || $subject->companyId === null) {
            return [];
        }
        // Matches the store's own invariant (TrainingEffectivenessStore links a
        // review's participant to the assessment's employee id): participants
        // are keyed by the same id space as the authorized stable subject id.
        $participantIds = TrainingParticipant::query()
            ->forCompany($subject->tenantId, $subject->companyId)
            ->where('employee_subject_id', (string) $subject->stableId)
            ->pluck('id');

        return TrainingEffectivenessReview::query()
            ->forCompany($subject->tenantId, $subject->companyId)
            ->whereIn('training_participant_id', $participantIds)
            ->orderBy('id')
            ->get(['stage', 'state', 'outcome', 'reviewed_on', 'outcome_recorded_at'])
            ->map(fn (TrainingEffectivenessReview $row): OwnEffectivenessOutcome => new OwnEffectivenessOutcome(
                $row->stage->value,
                $row->state->value,
                $row->outcome?->value,
                $row->reviewed_on?->toDateString(),
                $row->outcome_recorded_at?->toIso8601String(),
            ))->all();
    }
}
