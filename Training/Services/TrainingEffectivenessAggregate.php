<?php

namespace App\Domains\People\Training\Services;

use App\Core\Employee\Models\Employee;
use App\Core\User\Models\User;
use App\Domains\People\Training\Data\CheckpointEffectiveness;
use App\Domains\People\Training\Data\CourseEffectiveness;
use App\Domains\People\Training\Data\EffectivenessComment;
use App\Domains\People\Training\Enums\AttendanceStatus;
use App\Domains\People\Training\Enums\EffectivenessCheckpoint;
use App\Domains\People\Training\Models\TrainingEffectivenessAnswer;
use App\Domains\People\Training\Models\TrainingEvent;
use App\Domains\People\Training\Models\TrainingParticipant;
use App\Domains\People\Training\Models\TrainingParticipationFact;
use Carbon\CarbonImmutable;
use DateTimeInterface;

/**
 * What the 30/60/90-day answers add up to, per course.
 *
 * The number that matters is the answer rate, and it is only honest if the
 * denominator is every checkpoint that has *opened*. A rate computed over
 * answers is always 100% and tells HR nothing about the HODs who never
 * replied — which is the thing they are looking at this page to find.
 *
 * That includes checkpoints a later one superseded unanswered: 0013-a closes
 * the thirty-day question once sixty days pass, and an unanswered question
 * that closed is still a question nobody answered.
 */
final class TrainingEffectivenessAggregate
{
    public const VIEW = 'people.training.effectiveness-aggregate.view';

    private const WINDOW_MONTHS = 12;

    public function __construct(private readonly TrainingEffectivenessPolicy $policies) {}

    /**
     * One row per course with an attended event that ended inside the window.
     *
     * @return list<CourseEffectiveness>
     */
    public function perCourse(
        int $tenantId,
        int $companyEntityId,
        ?int $departmentEntityId = null,
        ?DateTimeInterface $asOf = null,
    ): array {
        $now = $asOf === null ? CarbonImmutable::now() : CarbonImmutable::instance($asOf);

        $events = TrainingEvent::query()->forCompany($tenantId, $companyEntityId)
            ->where('ends_at', '<=', $now)
            // The window runs from the event's end, as the checkpoint clock
            // does, so a course drops off the page the same way it stopped
            // being asked about.
            ->where('ends_at', '>=', $now->subMonths(self::WINDOW_MONTHS))
            ->orderBy('id')
            ->get();

        if ($events->isEmpty()) {
            return [];
        }

        $facts = TrainingParticipationFact::query()->forCompany($tenantId, $companyEntityId)
            ->where('attendance', AttendanceStatus::Present)
            ->whereIn('event_id', $events->modelKeys())
            ->get();

        if ($facts->isEmpty()) {
            return [];
        }

        $participants = TrainingParticipant::query()->forCompany($tenantId, $companyEntityId)
            ->whereIn('id', $facts->pluck('participant_id')->unique())
            ->get()
            ->keyBy('id');
        $answers = TrainingEffectivenessAnswer::query()->forCompany($tenantId, $companyEntityId)
            ->whereIn('participant_id', $participants->keys())
            ->get()
            ->keyBy(static fn (TrainingEffectivenessAnswer $answer): string => $answer->participant_id.':'.$answer->checkpoint->value);

        $departments = $this->departmentsOf($companyEntityId, $participants->pluck('employee_subject_id')->all());
        $names = $this->answererNames($companyEntityId, $answers->pluck('answered_by_user_id')->unique()->all());

        $rows = [];

        foreach ($events->groupBy('course_id') as $courseId => $courseEvents) {
            $tally = [];
            $comments = [];

            foreach (EffectivenessCheckpoint::cases() as $checkpoint) {
                $tally[$checkpoint->value] = ['opened' => 0, 'ratings' => []];
            }

            foreach ($courseEvents as $event) {
                // The offsets in force when this event ended, so a later
                // policy change never moves an already-counted denominator.
                $elapsed = EffectivenessCheckpoint::elapsedAt(
                    $event->ends_at, $now,
                    $this->policies->offsetsFor($tenantId, $companyEntityId, $event->ends_at),
                );

                foreach ($facts->where('event_id', $event->id) as $fact) {
                    $participant = $participants->get($fact->participant_id);

                    if ($participant === null) {
                        continue;
                    }
                    if ($departmentEntityId !== null
                        && ($departments[(int) $participant->employee_subject_id] ?? null) !== $departmentEntityId) {
                        continue;
                    }

                    foreach ($elapsed as $checkpoint) {
                        $tally[$checkpoint->value]['opened']++;
                        $answer = $answers->get($participant->id.':'.$checkpoint->value);

                        if ($answer === null) {
                            continue;
                        }

                        $tally[$checkpoint->value]['ratings'][] = (int) $answer->rating;
                        $comments[] = new EffectivenessComment(
                            checkpoint: $checkpoint,
                            rating: (int) $answer->rating,
                            comment: (string) $answer->comment,
                            answeredBy: $names[(int) $answer->answered_by_user_id] ?? 'Unknown',
                        );
                    }
                }
            }

            if (array_sum(array_column($tally, 'opened')) === 0) {
                continue;
            }

            $rows[] = new CourseEffectiveness(
                courseId: (int) $courseId,
                courseTitle: (string) $courseEvents->first()->course_title_snapshot,
                checkpoints: $this->summarise($tally),
                comments: $comments,
            );
        }

        return $rows;
    }

    /**
     * @param  array<string, array{opened: int, ratings: list<int>}>  $tally
     * @return array<string, CheckpointEffectiveness>
     */
    private function summarise(array $tally): array
    {
        $summary = [];

        foreach ($tally as $checkpoint => $counts) {
            $answered = count($counts['ratings']);
            $summary[$checkpoint] = new CheckpointEffectiveness(
                opened: $counts['opened'],
                answered: $answered,
                answerRate: $counts['opened'] === 0 ? null : (int) round($answered / $counts['opened'] * 100),
                meanRating: $answered === 0 ? null : round(array_sum($counts['ratings']) / $answered, 2),
            );
        }

        return $summary;
    }

    /**
     * @param  list<mixed>  $employeeEntityIds
     * @return array<int, int|null>
     */
    private function departmentsOf(int $companyEntityId, array $employeeEntityIds): array
    {
        return Employee::query()
            ->where('company_id', $companyEntityId)
            ->whereIn('id', array_map(intval(...), $employeeEntityIds))
            ->pluck('department_id', 'id')
            ->map(static fn (mixed $id): ?int => $id === null ? null : (int) $id)
            ->all();
    }

    /**
     * @param  list<mixed>  $userIds
     * @return array<int, string>
     */
    private function answererNames(int $companyEntityId, array $userIds): array
    {
        $users = User::query()
            ->where('company_id', $companyEntityId)
            ->whereIn('id', array_map(intval(...), $userIds))
            ->pluck('employee_id', 'id');

        $employees = Employee::query()
            ->where('company_id', $companyEntityId)
            ->whereIn('id', $users->filter()->values()->all())
            ->pluck('full_name', 'id');

        return $users
            ->map(static fn (mixed $employeeId): string => $employeeId === null
                ? 'Unknown'
                : (string) ($employees[(int) $employeeId] ?? 'Unknown'))
            ->all();
    }
}
