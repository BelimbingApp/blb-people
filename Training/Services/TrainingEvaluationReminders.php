<?php

namespace App\Domains\People\Training\Services;

use App\Domains\People\Training\Data\DueEvaluation;
use App\Domains\People\Training\Enums\AttendanceStatus;
use App\Domains\People\Training\Enums\TrainingEvaluationStatus;
use App\Domains\People\Training\Models\TrainingEvaluation;
use App\Domains\People\Training\Models\TrainingEvaluationReminder;
use App\Domains\People\Training\Models\TrainingEvent;
use App\Domains\People\Training\Models\TrainingParticipant;
use App\Domains\People\Training\Models\TrainingParticipationFact;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Remind participants three days before their evaluation is due, and every
 * day it stays overdue.
 *
 * The unit is the attended participant, not the evaluation row. Nothing writes
 * a row before submission, so a rule over rows alone would never fire for the
 * ordinary case: somebody attended and has not yet said anything. The due
 * date of a participant without a row is the clock submit() already uses,
 * fourteen days after the event ends. A row that carries its own due_on wins,
 * so an HR extension of the window is honoured.
 *
 * Writing is separate from finding, as in Performance's OverdueReviewReminders:
 * due() is the rule, remind() is the record, and --dry-run reads the former.
 */
final class TrainingEvaluationReminders
{
    /** "Normally within three days" (#35): the reminder starts three days out. */
    public const REMIND_WITHIN_DAYS = 3;

    /** The evaluation window submit() enforces. */
    public const WINDOW_DAYS = 14;

    /**
     * Every attended participant in this company whose evaluation is not
     * completed and is due within three days or overdue.
     *
     * Dates are compared in PHP after loading: due_on is a date column that
     * SQLite stores with a time part, so a string predicate would silently
     * miss rows, and the event-clock fallback has no column to compare at all.
     *
     * @return list<DueEvaluation>
     */
    public function due(int $tenantId, int $companyEntityId, ?DateTimeInterface $asOf = null): array
    {
        $today = $this->moment($asOf)->startOfDay();

        $facts = TrainingParticipationFact::query()->forCompany($tenantId, $companyEntityId)
            ->where('attendance', AttendanceStatus::Present)
            ->orderBy('participant_id')
            ->get()
            ->unique('participant_id');

        if ($facts->isEmpty()) {
            return [];
        }

        $events = TrainingEvent::query()->forCompany($tenantId, $companyEntityId)
            ->whereIn('id', $facts->pluck('event_id')->unique())
            ->get()
            ->keyBy('id');
        $participants = TrainingParticipant::query()->forCompany($tenantId, $companyEntityId)
            ->whereIn('id', $facts->pluck('participant_id'))
            ->get()
            ->keyBy('id');
        $evaluations = TrainingEvaluation::query()->forCompany($tenantId, $companyEntityId)
            ->whereIn('participant_id', $facts->pluck('participant_id'))
            ->get()
            ->keyBy('participant_id');

        $rows = [];

        foreach ($facts as $fact) {
            $event = $events->get($fact->event_id);
            $participant = $participants->get($fact->participant_id);

            if ($event === null || $participant === null) {
                continue;
            }

            /** @var TrainingEvaluation|null $evaluation */
            $evaluation = $evaluations->get($fact->participant_id);

            if ($evaluation?->status === TrainingEvaluationStatus::Completed) {
                continue;
            }

            $dueOn = $evaluation?->due_on === null
                ? $event->ends_at->addDays(self::WINDOW_DAYS)->startOfDay()
                : CarbonImmutable::instance($evaluation->due_on)->startOfDay();
            $daysOverdue = self::daysBetween($dueOn, $today);

            if ($daysOverdue < -self::REMIND_WITHIN_DAYS) {
                continue;
            }

            $rows[] = new DueEvaluation(
                participantId: (int) $participant->id,
                eventId: (int) $event->id,
                evaluationId: $evaluation === null ? null : (int) $evaluation->id,
                employeeEntityId: (int) $participant->employee_subject_id,
                eventTitle: (string) $event->course_title_snapshot,
                dueOn: $dueOn,
                daysOverdue: $daysOverdue,
            );
        }

        return $rows;
    }

    /** @return list<DueEvaluation> */
    public function overdue(int $tenantId, int $companyEntityId, ?DateTimeInterface $asOf = null): array
    {
        return array_values(array_filter(
            $this->due($tenantId, $companyEntityId, $asOf),
            static fn (DueEvaluation $row): bool => $row->overdue(),
        ));
    }

    /**
     * Write today's reminders and return only the ones this run created.
     *
     * @return list<TrainingEvaluationReminder>
     */
    public function remind(int $tenantId, int $companyEntityId, ?DateTimeInterface $asOf = null): array
    {
        $now = $this->moment($asOf);
        $dayKey = self::dayKey($now);
        $written = [];

        foreach ($this->due($tenantId, $companyEntityId, $now) as $row) {
            $already = TrainingEvaluationReminder::query()->forCompany($tenantId, $companyEntityId)
                ->where('participant_id', $row->participantId)
                ->where('day_key', $dayKey)
                ->exists();

            if ($already) {
                continue;
            }

            // The guarantee is the unique key, not the check above: the check
            // only spares the ordinary repeat run a failed insert.
            try {
                $written[] = TrainingEvaluationReminder::query()->create([
                    'tenant_id' => $tenantId,
                    'company_entity_id' => $companyEntityId,
                    'event_id' => $row->eventId,
                    'participant_id' => $row->participantId,
                    'evaluation_id' => $row->evaluationId,
                    'due_on' => $row->dueOn->toDateString(),
                    'day_key' => $dayKey,
                    'notified_at' => $now,
                ]);
            } catch (UniqueConstraintViolationException) {
                // Two runs overlapping is what the unique key is for.
            }
        }

        return $written;
    }

    /** The calendar day a reminder belongs to, e.g. 2026-09-07. */
    public static function dayKey(DateTimeInterface $moment): string
    {
        return CarbonImmutable::instance($moment)->toDateString();
    }

    /** Whole days from $from to $to; negative when $to is earlier. */
    private static function daysBetween(CarbonImmutable $from, CarbonImmutable $to): int
    {
        return (int) round(($to->getTimestamp() - $from->getTimestamp()) / 86400);
    }

    private function moment(?DateTimeInterface $asOf): CarbonImmutable
    {
        return $asOf === null ? CarbonImmutable::now() : CarbonImmutable::instance($asOf);
    }
}
