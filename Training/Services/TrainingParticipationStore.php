<?php

namespace App\Domains\People\Training\Services;

use App\Base\Authz\Contracts\AuthorizationService;
use App\Base\Authz\DTO\Actor;
use App\Base\Authz\Exceptions\AuthorizationDeniedException;
use App\Base\Tenancy\Contracts\TenantContext;
use App\Core\User\Models\User;
use App\Domains\People\Provider\Contracts\ReadsWorkforceDirectory;
use App\Domains\People\Provider\Data\WorkforceEmployee;
use App\Domains\People\Provider\Data\WorkforceSubject;
use App\Domains\People\Provider\Enums\WorkforceResourceType;
use App\Domains\People\Skills\Services\CompanyAttribution;
use App\Domains\People\Skills\Services\SkillAudience;
use App\Domains\People\Training\Data\ParticipationFactDraft;
use App\Domains\People\Training\Enums\AttendanceStatus;
use App\Domains\People\Training\Enums\TrainingEventStatus;
use App\Domains\People\Training\Exceptions\InvalidTrainingParticipationException;
use App\Domains\People\Training\Models\TrainingEvent;
use App\Domains\People\Training\Models\TrainingParticipant;
use App\Domains\People\Training\Models\TrainingParticipationFact;
use App\Domains\People\Training\Models\TrainingSession;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

final class TrainingParticipationStore
{
    public const MANAGE = 'people.training.participation.manage';

    public const CONFIRM = 'people.training.participation.verify';

    public const EVIDENCE = 'people.training.participation.evidence.assign';

    public function __construct(
        private readonly TenantContext $tenancy,
        private readonly ReadsWorkforceDirectory $directory,
        private readonly CompanyAttribution $companies,
        private readonly AuthorizationService $authorization,
        private readonly SkillAudience $audiences,
    ) {}

    public function defineSession(User $actor, int $companyId, int $eventId, string $reference, DateTimeInterface $startsAt, DateTimeInterface $endsAt): TrainingSession
    {
        $tenant = $this->scope($actor, $companyId, self::MANAGE);
        $event = $this->event($tenant, $companyId, $eventId);
        $this->authorizeEvent($actor, $event, false);
        $start = CarbonImmutable::instance($startsAt);
        $end = CarbonImmutable::instance($endsAt);
        if (! $this->reference($reference) || $start->lessThan($event->starts_at)
            || $end->greaterThan($event->ends_at) || $end->lessThanOrEqualTo($start)) {
            throw new InvalidTrainingParticipationException('A session must have a stable reference and fit within its delivery event.');
        }

        return TrainingSession::query()->create([
            'tenant_id' => $tenant, 'company_entity_id' => $companyId, 'event_id' => $eventId,
            'session_reference' => $reference, 'starts_at' => $start, 'ends_at' => $end,
            'created_by_user_id' => $actor->getKey(),
        ]);
    }

    public function recordAttendance(User $actor, int $companyId, int $sessionId, WorkforceSubject $subject, ParticipationFactDraft $draft): TrainingParticipationFact
    {
        $tenant = $this->scope($actor, $companyId, self::MANAGE);
        try {
            return DB::transaction(function () use ($actor, $companyId, $sessionId, $subject, $draft, $tenant): TrainingParticipationFact {
                $session = $this->session($tenant, $companyId, $sessionId);
                $event = $this->event($tenant, $companyId, (int) $session->event_id);
                $this->authorizeEvent($actor, $event, false);
                $employee = $this->employee($tenant, $companyId, $subject);
                if ($event->target_department_entity_id !== null
                    && $employee->organizationReference?->externalId !== (string) $event->target_department_entity_id) {
                    $this->deny();
                }
                $payload = $this->payload($actor, $session, $draft);
                $participant = TrainingParticipant::query()->forCompany($tenant, $companyId)->firstOrCreate([
                    'tenant_id' => $tenant, 'company_entity_id' => $companyId, 'event_id' => $event->id,
                    'provider_id' => $employee->reference->providerId,
                    'employee_subject_id' => $employee->reference->externalId,
                ], ['workforce_observed_at' => $employee->observedAt]);

                return TrainingParticipationFact::query()->create($payload + [
                    'tenant_id' => $tenant, 'company_entity_id' => $companyId,
                    'participant_id' => $participant->id, 'session_id' => $session->id, 'event_id' => $event->id,
                ]);
            });
        } catch (QueryException) {
            throw new InvalidTrainingParticipationException('The participation fact conflicts with retained session or source evidence.');
        }
    }

    public function revise(User $actor, int $companyId, int $factId, ParticipationFactDraft $draft): TrainingParticipationFact
    {
        $tenant = $this->scope($actor, $companyId, self::MANAGE);

        return DB::transaction(function () use ($actor, $companyId, $factId, $draft, $tenant): TrainingParticipationFact {
            $fact = $this->fact($tenant, $companyId, $factId);
            $session = $this->session($tenant, $companyId, (int) $fact->session_id);
            $this->authorizeEvent($actor, $this->event($tenant, $companyId, (int) $session->event_id), false);
            $this->requireUnconfirmed($fact);
            $this->authorizeEvidence($actor, $fact);
            $fact->update($this->payload($actor, $session, $draft));

            return $fact->refresh();
        });
    }

    public function confirm(User $actor, int $companyId, int $factId): TrainingParticipationFact
    {
        $tenant = $this->scope($actor, $companyId, self::CONFIRM);

        return DB::transaction(function () use ($actor, $companyId, $factId, $tenant): TrainingParticipationFact {
            $fact = $this->fact($tenant, $companyId, $factId);
            $session = $this->session($tenant, $companyId, (int) $fact->session_id);
            $this->authorizeEvent($actor, $this->event($tenant, $companyId, (int) $session->event_id), true);
            $this->requireUnconfirmed($fact);
            $this->authorizeEvidence($actor, $fact);
            $fact->update([
                'confirmed_by_user_id' => $actor->getKey(), 'confirmed_capability' => self::CONFIRM,
                'confirmed_at' => now(),
            ]);

            return $fact->refresh();
        });
    }

    private function scope(User $actor, int $companyId, string $capability): int
    {
        $tenant = $this->tenancy->currentTenantId();
        $currentActor = $actor->exists ? User::query()->find($actor->getKey()) : null;
        if ($tenant === null || $currentActor === null || $currentActor->getCompanyId() !== $actor->getCompanyId()
            || (int) $currentActor->tenant_id !== $tenant
            || ! $this->companies->mayActFor($actor, $companyId)) {
            $this->deny();
        }
        $this->authorization->authorize(Actor::forUser($actor), $capability);

        return $tenant;
    }

    private function authorizeEvent(User $actor, TrainingEvent $event, bool $confirm): void
    {
        $capability = $confirm ? self::CONFIRM : self::MANAGE;
        try {
            if (in_array(SkillAudience::HR, $this->audiences->authorizeAudience($actor, $capability), true)) {
                return;
            }
        } catch (AuthorizationDeniedException) {
            // A scoped trainer need not hold an HR, HOD, assessor or self audience.
        }
        $employee = $this->directory->employeeForUser((string) $event->company_entity_id, (int) $actor->getKey());
        if ($confirm || $employee === null || ! $employee->active || $employee->userReferenceRevoked
            || $employee->companyReference->externalId !== (string) $event->company_entity_id
            || $employee->userReference?->providerId !== $employee->reference->providerId
            || $employee->userReference?->externalId !== (string) $actor->getKey()
            || ! in_array($employee->reference->externalId, [
                (string) $event->organizer_employee_entity_id, (string) $event->internal_trainer_employee_entity_id,
            ], true)) {
            $this->deny();
        }
    }

    private function employee(int $tenant, int $companyId, WorkforceSubject $subject): WorkforceEmployee
    {
        if ($subject->tenantId !== $tenant || $subject->companyId !== $companyId
            || $subject->type !== WorkforceResourceType::Employee || $subject->externalReference === null
            || $subject->externalReference->externalId !== $subject->stableId
            || strlen($subject->stableId) > 160 || strlen($subject->externalReference->providerId) > 80) {
            $this->deny();
        }
        foreach ($this->directory->employees((string) $companyId) as $employee) {
            if ($employee->active && $employee->reference == $subject->externalReference
                && $employee->companyReference->externalId === (string) $companyId) {
                return $employee;
            }
        }
        $this->deny();
    }

    private function payload(User $actor, TrainingSession $session, ParticipationFactDraft $draft): array
    {
        if ($session->ends_at->isFuture() || $draft->actualMinutes < 0
            || $draft->actualMinutes > (int) $session->starts_at->diffInMinutes($session->ends_at)
            || ($draft->attendance !== AttendanceStatus::Present && $draft->actualMinutes !== 0)
            || ! $this->reference($draft->source, 80) || ! $this->reference($draft->sourceReference)
            || ($draft->certificateReference !== null && ! $this->reference($draft->certificateReference))
            || ($draft->certificateReference === null && ($draft->certificateValidFrom !== null || $draft->certificateValidUntil !== null))
            || ($draft->certificateValidFrom !== null && $draft->certificateValidUntil !== null
                && CarbonImmutable::instance($draft->certificateValidUntil)->lessThan(CarbonImmutable::instance($draft->certificateValidFrom)))
            || ! array_is_list($draft->evidenceReferences)
            || count($draft->evidenceReferences) > 100) {
            throw new InvalidTrainingParticipationException('Participation facts do not fit the session or evidence contract.');
        }
        foreach ($draft->evidenceReferences as $reference) {
            if (! is_string($reference) || ! $this->reference($reference)) {
                throw new InvalidTrainingParticipationException('Evidence must use opaque governed-document references.');
            }
        }
        if ($draft->evidenceReferences !== [] || $draft->certificateReference !== null) {
            $this->authorization->authorize(Actor::forUser($actor), self::EVIDENCE);
        }

        return [
            'attendance' => $draft->attendance, 'actual_minutes' => $draft->actualMinutes,
            'pre_test' => $draft->preTest?->toArray(), 'post_test' => $draft->postTest?->toArray(),
            'certificate_reference' => $draft->certificateReference,
            'certificate_valid_from' => $draft->certificateValidFrom, 'certificate_valid_until' => $draft->certificateValidUntil,
            'evidence_references' => $draft->evidenceReferences, 'source' => $draft->source,
            'source_reference' => $draft->sourceReference, 'recorded_by_user_id' => $actor->getKey(),
            'recorded_capability' => self::MANAGE, 'recorded_at' => now(),
        ];
    }

    private function event(int $tenant, int $companyId, int $id): TrainingEvent
    {
        $event = TrainingEvent::query()->forCompany($tenant, $companyId)->find($id);
        if ($event === null || $event->status === TrainingEventStatus::Cancelled) {
            $this->deny();
        }

        return $event;
    }

    private function session(int $tenant, int $companyId, int $id): TrainingSession
    {
        return TrainingSession::query()->forCompany($tenant, $companyId)->find($id) ?? $this->deny();
    }

    private function fact(int $tenant, int $companyId, int $id): TrainingParticipationFact
    {
        return TrainingParticipationFact::query()->forCompany($tenant, $companyId)->lockForUpdate()->find($id) ?? $this->deny();
    }

    private function requireUnconfirmed(TrainingParticipationFact $fact): void
    {
        if ($fact->confirmed_at !== null) {
            throw new InvalidTrainingParticipationException('Confirmed participation requires a separate traceable correction.');
        }
    }

    private function authorizeEvidence(User $actor, TrainingParticipationFact $fact): void
    {
        if ($fact->evidence_references !== [] || $fact->certificate_reference !== null) {
            $this->authorization->authorize(Actor::forUser($actor), self::EVIDENCE);
        }
    }

    private function reference(string $value, int $maximum = 160): bool
    {
        return strlen($value) <= $maximum && preg_match('/^[a-zA-Z0-9][a-zA-Z0-9_.:-]*$/D', $value) === 1;
    }

    private function deny(): never
    {
        throw new InvalidTrainingParticipationException('The participation operation is unavailable in the current scope.');
    }
}
