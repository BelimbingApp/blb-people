<?php

namespace App\Domains\People\Training\Services;

use App\Base\Tenancy\Contracts\TenantContext;
use App\Domains\People\Training\Contracts\SummarizesTrainingParticipation;
use App\Domains\People\Training\Data\TrainingParticipationSummary;
use App\Domains\People\Training\Enums\AttendanceStatus;
use App\Domains\People\Training\Models\TrainingEvent;
use App\Domains\People\Training\Models\TrainingParticipant;
use App\Domains\People\Training\Models\TrainingParticipationFact;

/**
 * Event participation counts derived from participant records (0011-e).
 *
 * Definitions, per docs/contracts/training-participation.md:
 *  - enrolled:  participant rows of the event without `withdrawn_at`.
 *  - attended:  enrolled participants with at least one session fact whose
 *               attendance is `present` (the enum has no partial state).
 *  - completed: attended participants whose latest recorded fact carries an
 *               applicable post-test with a score. A missing or
 *               not-applicable post-test is not a completion: "missing and
 *               not applicable remain distinct from zero or failed".
 *  - passed:    completed participants whose post-test score is at or above
 *               its pass mark, as the recorded result says.
 *
 * Three queries for any number of events (the company's own events, their
 * participants, then the facts), all pinned to the tenant and company. An
 * event the company owns but nobody joined yet is all zeros; an event the
 * company does not own gets no key at all rather than zeros.
 */
final class DatabaseTrainingParticipationSummary implements SummarizesTrainingParticipation
{
    public function __construct(private readonly TenantContext $tenants) {}

    public function forEvents(int $companyEntityId, array $trainingEventIds): array
    {
        $eventIds = array_values(array_unique(array_map('intval', $trainingEventIds)));
        if ($eventIds === []) {
            return [];
        }
        $tenantId = $this->tenants->requireTenantId();

        $owned = TrainingEvent::query()->forCompany($tenantId, $companyEntityId)
            ->whereIn('id', $eventIds)->orderBy('id')->pluck('id')->map(static fn ($id): int => (int) $id)->all();
        if ($owned === []) {
            return [];
        }

        $participants = TrainingParticipant::query()->forCompany($tenantId, $companyEntityId)
            ->whereIn('event_id', $owned)
            ->whereNull('withdrawn_at')
            ->get(['id', 'event_id']);

        $facts = $participants->isEmpty() ? collect() : TrainingParticipationFact::query()->forCompany($tenantId, $companyEntityId)
            ->whereIn('participant_id', $participants->pluck('id')->all())
            ->orderBy('recorded_at')->orderBy('id')
            ->get(['participant_id', 'attendance', 'post_test', 'recorded_at'])
            ->groupBy('participant_id');

        $byEvent = $participants->groupBy('event_id');
        $summaries = [];
        foreach ($owned as $eventId) {
            $rows = $byEvent->get($eventId, collect());
            $attended = $completed = $passed = 0;
            foreach ($rows as $participant) {
                $own = $facts->get($participant->id, collect());
                if (! $own->contains(static fn (TrainingParticipationFact $f): bool => $f->attendance === AttendanceStatus::Present)) {
                    continue;
                }
                $attended++;
                $result = $own->last()?->post_test;
                if (! is_array($result) || ($result['applicable'] ?? false) !== true || ! isset($result['score'])) {
                    continue;
                }
                $completed++;
                if (($result['passed'] ?? null) === true) {
                    $passed++;
                }
            }
            $summaries[(int) $eventId] = new TrainingParticipationSummary($rows->count(), $attended, $completed, $passed);
        }

        return $summaries;
    }
}
