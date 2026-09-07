<?php

namespace App\Domains\People\Training\Services;

use App\Base\Authz\Contracts\AuthorizationService;
use App\Base\Authz\DTO\Actor;
use App\Base\Tenancy\Contracts\TenantContext;
use App\Core\User\Models\User;
use App\Domains\People\Provider\Contracts\ReadsWorkforceDirectory;
use App\Domains\People\Provider\Data\WorkforceEmployee;
use App\Domains\People\Skills\Services\CompanyAttribution;
use App\Domains\People\Training\Enums\AttendanceStatus;
use App\Domains\People\Training\Enums\TrainingEvaluationStatus;
use App\Domains\People\Training\Exceptions\InvalidTrainingEvaluationException;
use App\Domains\People\Training\Models\TrainingCourse;
use App\Domains\People\Training\Models\TrainingEvaluation;
use App\Domains\People\Training\Models\TrainingEvent;
use App\Domains\People\Training\Models\TrainingParticipant;
use App\Domains\People\Training\Models\TrainingParticipationFact;

final class TrainingEvaluationSubmissionStore
{
    public const SUBMIT = 'people.training.evaluation.submit';

    public const CRITERIA_VERSION = '0012-a.v1';

    public function __construct(
        private readonly TenantContext $tenancy,
        private readonly ReadsWorkforceDirectory $directory,
        private readonly CompanyAttribution $companies,
        private readonly AuthorizationService $authorization,
    ) {}

    /**
     * @param  array{relevance: int, trainer_effectiveness: int, materials_exercises: int, pace_duration: int, practical_usefulness: int}  $ratings
     */
    public function submit(User $actor, int $companyId, int $eventId, array $ratings, ?string $comment): TrainingEvaluation
    {
        [$tenant, $employee] = $this->scope($actor, $companyId);
        $participant = $this->participant($tenant, $companyId, $eventId, $employee);
        $event = $this->attendedEvent($tenant, $companyId, $participant);
        if (now()->isAfter($event->ends_at->addDays(14))) {
            throw new InvalidTrainingEvaluationException('This evaluation window has closed. Ask HR if a traceable correction is needed.');
        }

        foreach ($ratings as $rating) {
            if (! is_int($rating) || $rating < 1 || $rating > 5) {
                throw new InvalidTrainingEvaluationException('Rate every evaluation item from 1 to 5.');
            }
        }
        if (array_keys($ratings) !== ['relevance', 'trainer_effectiveness', 'materials_exercises', 'pace_duration', 'practical_usefulness']) {
            throw new InvalidTrainingEvaluationException('The evaluation criteria do not match this form. Reload the page and try again.');
        }
        $comment = $this->comment($comment);

        return TrainingEvaluation::query()->updateOrCreate([
            'tenant_id' => $tenant,
            'company_entity_id' => $companyId,
            'participant_id' => (int) $participant->id,
        ], [
            'event_id' => (int) $event->id,
            'employee_subject_id' => $employee->reference->externalId,
            'criteria_version' => self::CRITERIA_VERSION,
            ...$ratings,
            'issues_or_improvements' => $comment,
            'status' => TrainingEvaluationStatus::Completed,
            'due_on' => $event->ends_at->addDays(14)->toDateString(),
            'completed_at' => now(),
            'submitted_by_user_id' => (int) $actor->getKey(),
            'entry_source' => 'self',
        ]);
    }

    /**
     * @return list<array{event_id: int, title: string, ends_at: mixed, closes_at: mixed, open: bool, evaluation: ?TrainingEvaluation}>
     */
    public function visibleEvents(User $actor, int $companyId): array
    {
        [$tenant, $employee] = $this->scope($actor, $companyId);
        $participants = TrainingParticipant::query()->forCompany($tenant, $companyId)
            ->where('provider_id', $employee->reference->providerId)
            ->where('employee_subject_id', $employee->reference->externalId)
            ->get();
        if ($participants->isEmpty()) {
            return [];
        }

        $presentParticipantIds = TrainingParticipationFact::query()->forCompany($tenant, $companyId)
            ->whereIn('participant_id', $participants->pluck('id')->all())
            ->where('attendance', AttendanceStatus::Present->value)
            ->pluck('participant_id')->map(fn ($id): int => (int) $id)->unique();
        $participants = $participants->whereIn('id', $presentParticipantIds->all());
        $events = TrainingEvent::query()->forCompany($tenant, $companyId)
            ->whereIn('id', $participants->pluck('event_id')->all())->get()->keyBy('id');
        $courses = TrainingCourse::query()->forCompany($tenant, $companyId)
            ->whereIn('id', $events->pluck('course_id')->all())->get()->keyBy('id');
        $evaluations = TrainingEvaluation::query()->forCompany($tenant, $companyId)
            ->whereIn('participant_id', $participants->pluck('id')->all())->get()->keyBy('participant_id');

        return $participants->map(function (TrainingParticipant $participant) use ($courses, $events, $evaluations): ?array {
            $event = $events->get($participant->event_id);
            if ($event === null) {
                return null;
            }
            $closesAt = $event->ends_at->addDays(14);

            return [
                'event_id' => (int) $event->id,
                'title' => (string) ($courses->get($event->course_id)?->title ?? 'Training event'),
                'ends_at' => $event->ends_at,
                'closes_at' => $closesAt,
                'open' => ! now()->isAfter($closesAt),
                'evaluation' => $evaluations->get($participant->id),
            ];
        })->filter()->sortByDesc('ends_at')->values()->all();
    }

    /** @return array{0: int, 1: WorkforceEmployee} */
    private function scope(User $actor, int $companyId): array
    {
        $tenant = $this->tenancy->currentTenantId();
        $currentActor = $actor->exists ? User::query()->find($actor->getKey()) : null;
        if ($tenant === null || $currentActor === null || $currentActor->getCompanyId() !== $actor->getCompanyId()
            || (int) $currentActor->tenant_id !== $tenant || ! $this->companies->mayActFor($actor, $companyId)) {
            $this->deny();
        }
        $this->authorization->authorize(Actor::forUser($actor), self::SUBMIT);
        $employee = $this->directory->employeeForUser((string) $companyId, (int) $actor->getKey());
        if ($employee === null || ! $employee->active || $employee->userReferenceRevoked
            || $employee->companyReference->externalId !== (string) $companyId
            || $employee->userReference?->providerId !== $employee->reference->providerId
            || $employee->userReference?->externalId !== (string) $actor->getKey()) {
            $this->deny();
        }

        return [$tenant, $employee];
    }

    private function participant(int $tenant, int $companyId, int $eventId, WorkforceEmployee $employee): TrainingParticipant
    {
        return TrainingParticipant::query()->forCompany($tenant, $companyId)
            ->where('event_id', $eventId)
            ->where('provider_id', $employee->reference->providerId)
            ->where('employee_subject_id', $employee->reference->externalId)
            ->first() ?? $this->deny();
    }

    private function attendedEvent(int $tenant, int $companyId, TrainingParticipant $participant): TrainingEvent
    {
        $attended = TrainingParticipationFact::query()->forCompany($tenant, $companyId)
            ->where('participant_id', $participant->id)
            ->where('attendance', AttendanceStatus::Present->value)->exists();
        if (! $attended) {
            throw new InvalidTrainingEvaluationException('An evaluation is available only for training you attended.');
        }

        return TrainingEvent::query()->forCompany($tenant, $companyId)
            ->whereKey($participant->event_id)->first() ?? $this->deny();
    }

    private function comment(?string $comment): ?string
    {
        $comment = trim((string) $comment);
        if (mb_strlen($comment) > 2000) {
            throw new InvalidTrainingEvaluationException('Keep the evaluation comment to 2,000 characters or fewer.');
        }

        return $comment === '' ? null : $comment;
    }

    private function deny(): never
    {
        throw new InvalidTrainingEvaluationException('The training evaluation is unavailable in the current scope.');
    }
}
