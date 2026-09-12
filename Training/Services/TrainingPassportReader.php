<?php

namespace App\Domains\People\Training\Services;

use App\Core\User\Models\User;
use App\Domains\People\Provider\Contracts\ReadsWorkforceDirectory;
use App\Domains\People\Provider\Data\WorkforceSubject;
use App\Domains\People\Provider\Exceptions\WorkforceProjectionException;
use App\Domains\People\Skills\Models\Skill;
use App\Domains\People\Training\Data\TrainingPassport;
use App\Domains\People\Training\Data\TrainingPassportCertificate;
use App\Domains\People\Training\Data\TrainingPassportContext;
use App\Domains\People\Training\Data\TrainingPassportEvent;
use App\Domains\People\Training\Data\TrainingPassportSkill;
use App\Domains\People\Training\Models\TrainingCourseSkill;
use App\Domains\People\Training\Models\TrainingEvent;
use App\Domains\People\Training\Models\TrainingParticipant;
use App\Domains\People\Training\Models\TrainingParticipationFact;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

final class TrainingPassportReader
{
    public const VIEW_CAPABILITY = 'people.training.passport.view';

    public function __construct(
        private readonly TrainingPassportAccess $access,
        private readonly ReadsWorkforceDirectory $directory,
    ) {}

    public function read(User $actor, WorkforceSubject $subject): TrainingPassport
    {
        $this->access->authorize($actor, $subject);
        $tenantId = $subject->tenantId;
        $generatedAt = now()->toImmutable();
        $context = $this->context($subject, $generatedAt);

        $participants = TrainingParticipant::query()
            ->forCompany($tenantId, (int) $subject->companyId)
            ->where('employee_subject_id', $subject->stableId)
            ->orderBy('event_id')
            ->get(['id', 'event_id']);
        $participantIds = $participants->pluck('id')->map(intval(...))->all();
        $eventIds = $participants->pluck('event_id')->map(intval(...))->unique()->values()->all();

        if ($eventIds === []) {
            return new TrainingPassport($subject, $generatedAt, [], [], [], $context);
        }

        $events = TrainingEvent::query()
            ->forCompany($tenantId, (int) $subject->companyId)
            ->whereIn('id', $eventIds)
            ->orderByDesc('starts_at')
            ->get(['id', 'course_id', 'course_title_snapshot', 'starts_at', 'ends_at', 'status'])
            ->keyBy('id');
        $facts = TrainingParticipationFact::query()
            ->forCompany($tenantId, (int) $subject->companyId)
            ->current()
            ->whereIn('participant_id', $participantIds)
            ->orderBy('id')
            ->get([
                'event_id', 'participant_id', 'attendance', 'actual_minutes', 'certificate_reference',
                'certificate_valid_from', 'certificate_valid_until',
            ]);
        $factsByEvent = $facts->groupBy('event_id');

        $passportEvents = $events->map(function (TrainingEvent $event) use ($factsByEvent): TrainingPassportEvent {
            /** @var Collection<int, TrainingParticipationFact> $eventFacts */
            $eventFacts = $factsByEvent->get($event->id, collect());

            return new TrainingPassportEvent(
                (int) $event->id,
                (string) $event->course_title_snapshot,
                $event->starts_at,
                $event->ends_at,
                $event->status,
                $eventFacts->contains(fn (TrainingParticipationFact $fact): bool => $fact->attendance?->value === 'present'),
                (int) $eventFacts->sum('actual_minutes'),
            );
        })->values()->all();

        $passportCertificates = $facts
            ->filter(fn (TrainingParticipationFact $fact): bool => $fact->certificate_reference !== null)
            ->map(function (TrainingParticipationFact $fact) use ($events): TrainingPassportCertificate {
                $validUntil = $fact->certificate_valid_until;

                return new TrainingPassportCertificate(
                    (int) $fact->event_id,
                    (string) $events->get($fact->event_id)?->course_title_snapshot,
                    (string) $fact->certificate_reference,
                    $fact->certificate_valid_from,
                    $validUntil,
                    $validUntil !== null && CarbonImmutable::instance($validUntil)->isBefore(today()),
                );
            })->values()->all();

        $courseIds = $events->pluck('course_id')->map(intval(...))->unique()->values()->all();
        $skillIds = TrainingCourseSkill::query()
            ->where('tenant_id', $tenantId)
            ->whereIn('course_id', $courseIds)
            ->pluck('skill_id')
            ->map(intval(...))
            ->unique()
            ->values()
            ->all();
        $skills = Skill::query()
            ->forCompany($tenantId, (int) $subject->companyId)
            ->whereIn('id', $skillIds)
            ->orderBy('name')
            ->get(['id', 'code', 'name'])
            ->map(fn (Skill $skill): TrainingPassportSkill => new TrainingPassportSkill(
                (int) $skill->id, (string) $skill->code, (string) $skill->name,
            ))->values()->all();

        return new TrainingPassport(
            $subject,
            $generatedAt,
            $passportEvents,
            $passportCertificates,
            $skills,
            $context,
        );
    }

    /**
     * Who the workforce record describes and how fresh that description is
     * (0014-d). A dead directory marks the context unavailable instead of
     * failing the passport: events, certificates and skills are governed
     * records of their own and stay readable.
     */
    private function context(WorkforceSubject $subject, CarbonImmutable $generatedAt): TrainingPassportContext
    {
        try {
            $employees = $this->directory->employees((string) $subject->companyId);
            $units = $this->directory->organizationUnits((string) $subject->companyId);
        } catch (WorkforceProjectionException) {
            return TrainingPassportContext::unavailable();
        }

        $record = null;
        foreach ($employees as $employee) {
            if ($employee->active && $employee->companyReference->externalId === (string) $subject->companyId
                && $employee->reference->externalId === $subject->stableId) {
                $record = $employee;

                break;
            }
        }
        if ($record === null) {
            return TrainingPassportContext::unavailable();
        }

        $manager = null;
        if ($record->managerReference !== null) {
            foreach ($employees as $employee) {
                if ($employee->reference->externalId === $record->managerReference->externalId) {
                    $manager = $employee->displayName;

                    break;
                }
            }
        }
        $department = null;
        if ($record->organizationReference !== null) {
            foreach ($units as $unit) {
                if ($unit->reference->externalId === $record->organizationReference->externalId) {
                    $department = $unit->name;

                    break;
                }
            }
        }

        $threshold = (int) config('people-training.passport.workforce_context_max_age_hours', 24);

        return new TrainingPassportContext(
            displayName: $record->displayName,
            department: $department,
            position: $record->positionReference?->externalId,
            manager: $manager,
            observedAt: $record->observedAt,
            stale: $record->observedAt < $generatedAt->subHours($threshold),
            unavailable: false,
        );
    }
}
