<?php

namespace App\Domains\People\Training\Services;

use App\Core\Company\Models\Department;
use App\Core\Employee\Models\Employee;
use App\Core\User\Models\User;
use App\Domains\People\Training\Data\OpenEffectivenessCheckpoint;
use App\Domains\People\Training\Enums\AttendanceStatus;
use App\Domains\People\Training\Enums\EffectivenessCheckpoint;
use App\Domains\People\Training\Exceptions\InvalidTrainingEffectivenessException;
use App\Domains\People\Training\Models\TrainingEffectivenessAnswer;
use App\Domains\People\Training\Models\TrainingEffectivenessReminder;
use App\Domains\People\Training\Models\TrainingEvent;
use App\Domains\People\Training\Models\TrainingParticipant;
use App\Domains\People\Training\Models\TrainingParticipationFact;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Thirty, sixty and ninety days after somebody attended a course, ask their
 * HOD whether it is being used.
 *
 * The clock runs from the event's ends_at, never from when attendance was
 * recorded. A record entered late would otherwise push the question out, and
 * an answer that arrives when nobody remembers the training is not an answer.
 *
 * Only the newest due checkpoint is ever open. See EffectivenessCheckpoint for
 * why a superseded question is closed rather than left waiting.
 */
final class TrainingEffectivenessCheckpoints
{
    private const MIN_RATING = 1;

    private const MAX_RATING = 5;

    public function __construct(private readonly TrainingEffectivenessPolicy $policies) {}

    /**
     * Every question due right now in this company, answered or not.
     *
     * @return list<OpenEffectivenessCheckpoint>
     */
    public function open(int $tenantId, int $companyEntityId, ?DateTimeInterface $asOf = null): array
    {
        $now = $this->moment($asOf);

        $facts = TrainingParticipationFact::query()->forCompany($tenantId, $companyEntityId)
            ->where('attendance', AttendanceStatus::Present)
            ->orderBy('participant_id')
            ->get();

        if ($facts->isEmpty()) {
            return [];
        }

        $events = TrainingEvent::query()->forCompany($tenantId, $companyEntityId)
            ->whereIn('id', $facts->pluck('event_id')->unique())
            ->get()
            ->keyBy('id');
        $participants = TrainingParticipant::query()->forCompany($tenantId, $companyEntityId)
            ->whereIn('id', $facts->pluck('participant_id')->unique())
            ->get()
            ->keyBy('id');
        $answered = TrainingEffectivenessAnswer::query()->forCompany($tenantId, $companyEntityId)
            ->get()
            ->keyBy(static fn (TrainingEffectivenessAnswer $answer): string => $answer->participant_id.':'.$answer->checkpoint->value);

        $rows = [];
        // Resolved once per event, not per participant: every attendee of an
        // event shares the policy that was in force when it ended.
        $offsets = [];

        foreach ($facts as $fact) {
            $event = $events->get($fact->event_id);
            $participant = $participants->get($fact->participant_id);

            if ($event === null || $participant === null) {
                continue;
            }

            $checkpoint = EffectivenessCheckpoint::openAt(
                $event->ends_at, $now, $this->offsetsFor($tenantId, $companyEntityId, $event, $offsets),
            );

            if ($checkpoint === null) {
                continue;
            }

            $employeeEntityId = (int) $participant->employee_subject_id;

            $rows[] = new OpenEffectivenessCheckpoint(
                participantId: (int) $participant->id,
                eventId: (int) $event->id,
                employeeEntityId: $employeeEntityId,
                checkpoint: $checkpoint,
                hodUserId: $this->headUserOf($companyEntityId, $employeeEntityId),
                answered: $answered->has($participant->id.':'.$checkpoint->value),
            );
        }

        return $rows;
    }

    /**
     * Record or revise the answer for a checkpoint that is currently open.
     *
     * The participant id arrives from a request, so the department is checked
     * against the actor rather than taken on trust: holding the HOD role is
     * not the same as heading this person's department.
     */
    public function answer(
        User $actor,
        int $companyEntityId,
        int $participantId,
        EffectivenessCheckpoint $checkpoint,
        int $rating,
        string $comment,
    ): TrainingEffectivenessAnswer {
        $tenantId = (int) $actor->tenant_id;

        if ($rating < self::MIN_RATING || $rating > self::MAX_RATING) {
            throw new InvalidTrainingEffectivenessException('An effectiveness rating is between 1 and 5.');
        }
        if (trim($comment) === '') {
            throw new InvalidTrainingEffectivenessException('An effectiveness answer needs a comment.');
        }

        $row = $this->openRowFor($tenantId, $companyEntityId, $participantId);

        if ($row->checkpoint !== $checkpoint) {
            throw new InvalidTrainingEffectivenessException(
                "The {$checkpoint->label()} checkpoint is no longer open; {$row->checkpoint->label()} is.",
            );
        }
        if ($row->hodUserId === null || $row->hodUserId !== (int) $actor->getKey()) {
            throw new InvalidTrainingEffectivenessException(
                'Only the head of this participant\'s department answers their effectiveness checkpoint.',
            );
        }

        return TrainingEffectivenessAnswer::query()->updateOrCreate(
            [
                'tenant_id' => $tenantId, 'company_entity_id' => $companyEntityId,
                'participant_id' => $participantId, 'checkpoint' => $checkpoint,
            ],
            [
                'event_id' => $row->eventId, 'rating' => $rating, 'comment' => trim($comment),
                'answered_by_user_id' => $actor->getKey(), 'answered_at' => now(),
            ],
        );
    }

    /**
     * Write one reminder per participant and checkpoint, however often it runs.
     *
     * @return list<TrainingEffectivenessReminder>
     */
    public function remind(int $tenantId, int $companyEntityId, ?DateTimeInterface $asOf = null): array
    {
        $now = $this->moment($asOf);
        $written = [];

        foreach ($this->open($tenantId, $companyEntityId, $now) as $row) {
            if ($row->answered) {
                continue;
            }

            $already = TrainingEffectivenessReminder::query()->forCompany($tenantId, $companyEntityId)
                ->where('participant_id', $row->participantId)
                ->where('checkpoint', $row->checkpoint)
                ->exists();

            if ($already) {
                continue;
            }

            try {
                $written[] = TrainingEffectivenessReminder::query()->create([
                    'tenant_id' => $tenantId, 'company_entity_id' => $companyEntityId,
                    'event_id' => $row->eventId, 'participant_id' => $row->participantId,
                    'checkpoint' => $row->checkpoint, 'hod_user_id' => $row->hodUserId,
                    'notified_at' => $now,
                ]);
            } catch (UniqueConstraintViolationException) {
                // Two runs overlapping is what the unique key is for.
            }
        }

        return $written;
    }

    /**
     * The governed offsets for this event, memoised across its participants.
     *
     * @param  array<int, array<string, int>>  $memo
     * @return array<string, int>
     */
    private function offsetsFor(int $tenantId, int $companyEntityId, TrainingEvent $event, array &$memo): array
    {
        return $memo[(int) $event->id] ??= $this->policies
            ->offsetsFor($tenantId, $companyEntityId, $event->ends_at);
    }

    private function openRowFor(int $tenantId, int $companyEntityId, int $participantId): OpenEffectivenessCheckpoint
    {
        foreach ($this->open($tenantId, $companyEntityId) as $row) {
            if ($row->participantId === $participantId) {
                return $row;
            }
        }

        throw new InvalidTrainingEffectivenessException('No effectiveness checkpoint is open for this participant.');
    }

    /** The user account of the head of this employee's department, if any. */
    private function headUserOf(int $companyEntityId, int $employeeEntityId): ?int
    {
        $departmentId = Employee::query()
            ->where('company_id', $companyEntityId)
            ->whereKey($employeeEntityId)
            ->value('department_id');

        if ($departmentId === null) {
            return null;
        }

        $headId = Department::query()
            ->where('company_id', $companyEntityId)
            ->whereKey($departmentId)
            ->value('head_id');

        if ($headId === null) {
            return null;
        }

        $userId = User::query()
            ->where('company_id', $companyEntityId)
            ->where('employee_id', $headId)
            ->value('id');

        return $userId === null ? null : (int) $userId;
    }

    private function moment(?DateTimeInterface $asOf): CarbonImmutable
    {
        return $asOf === null ? CarbonImmutable::now() : CarbonImmutable::instance($asOf);
    }
}
