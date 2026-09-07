<?php

namespace App\Domains\People\Training\Services;

use App\Base\Authz\Contracts\AuthorizationService;
use App\Base\Authz\DTO\Actor;
use App\Base\Tenancy\Contracts\TenantContext;
use App\Core\User\Models\User;
use App\Domains\People\Provider\Contracts\ReadsWorkforceDirectory;
use App\Domains\People\Provider\Data\WorkforceEmployee;
use App\Domains\People\Skills\Services\CompanyAttribution;
use App\Domains\People\Skills\Services\SkillAudience;
use App\Domains\People\Training\Enums\AttendanceStatus;
use App\Domains\People\Training\Enums\TrainingEvaluationStatus;
use App\Domains\People\Training\Exceptions\InvalidTrainingEvaluationException;
use App\Domains\People\Training\Models\TrainingCourse;
use App\Domains\People\Training\Models\TrainingEvaluation;
use App\Domains\People\Training\Models\TrainingEvent;
use App\Domains\People\Training\Models\TrainingParticipant;
use App\Domains\People\Training\Models\TrainingParticipationFact;
use Illuminate\Support\Facades\DB;

final class TrainingEvaluationSubmissionStore
{
    public const SUBMIT = 'people.training.evaluation.submit';

    /** HR assisted (paper) entry on a participant's behalf (0012-f). */
    public const ASSIGN = 'people.training.evaluation.assign';

    public const CRITERIA_VERSION = '0012-a.v1';

    public const PAPER_REFERENCE_PREFIX = 'paper:';

    public function __construct(
        private readonly TenantContext $tenancy,
        private readonly ReadsWorkforceDirectory $directory,
        private readonly CompanyAttribution $companies,
        private readonly AuthorizationService $authorization,
        private readonly SkillAudience $audiences,
    ) {}

    /**
     * The self-service form of criteria version 0012-a.v1: five ratings and
     * one comment, completed in one write. Kept for that version's callers;
     * the row it writes stays pinned to 0012-a.v1.
     *
     * @param  array{relevance: int, trainer_effectiveness: int, materials_exercises: int, pace_duration: int, practical_usefulness: int}  $ratings
     */
    public function submit(User $actor, int $companyId, int $eventId, array $ratings, ?string $comment): TrainingEvaluation
    {
        [$tenant, $employee] = $this->scope($actor, $companyId);
        $participant = $this->participant($tenant, $companyId, $eventId, $employee);
        $event = $this->openAttendedEvent($tenant, $companyId, $participant);
        foreach ($ratings as $rating) {
            if (! is_int($rating) || $rating < 1 || $rating > 5) {
                throw new InvalidTrainingEvaluationException('Rate every evaluation item from 1 to 5.');
            }
        }
        if (array_keys($ratings) !== self::criteria(self::CRITERIA_VERSION)['ratings']) {
            throw new InvalidTrainingEvaluationException('The evaluation criteria do not match this form. Reload the page and try again.');
        }
        $answers = [...$ratings, 'issues_or_improvements' => $this->text('issues_or_improvements', $comment)];

        return $this->write($tenant, $companyId, $participant, $event, self::CRITERIA_VERSION, $answers, TrainingEvaluationStatus::Completed, $actor);
    }

    /**
     * HR keys in a completed paper form for a named participant (0012-f),
     * under the current criteria version (0012-g).
     *
     * The participant stays the employee subject; the HR user is the entering
     * actor; entry_source says the answers arrived on paper; the paper
     * reference is kept in notes. Assistance is not authority to change already
     * completed answers, so a completed evaluation — self or paper — is refused
     * rather than overwritten. The 14-day window of the self path is unchanged,
     * and so is the mandatory set: a paper form completes under the same rule
     * as complete().
     *
     * @param  array<string, mixed>  $answers  rating => int|null, free text => string|null
     */
    public function submitAssisted(User $actor, int $companyId, int $participantId, array $answers, string $paperReference): TrainingEvaluation
    {
        $tenant = $this->hrScope($actor, $companyId);
        $participant = TrainingParticipant::query()->forCompany($tenant, $companyId)
            ->whereKey($participantId)->first() ?? $this->deny();
        $event = $this->openAttendedEvent($tenant, $companyId, $participant);
        if ($this->existing($tenant, $companyId, $participant)?->status === TrainingEvaluationStatus::Completed) {
            throw new InvalidTrainingEvaluationException('This evaluation is already completed. Assisted entry does not replace completed answers.');
        }
        $version = self::currentCriteriaVersion();
        $answers = $this->answers($version, $answers);
        $this->requireMandatory($version, $answers);
        $paperReference = trim($paperReference);
        if ($paperReference === '' || mb_strlen($paperReference) > 160) {
            throw new InvalidTrainingEvaluationException('Give the paper form a reference of 1 to 160 characters.');
        }

        return $this->write(
            $tenant, $companyId, $participant, $event, $version, $answers, TrainingEvaluationStatus::Completed, $actor,
            self::PAPER_REFERENCE_PREFIX.$paperReference, TrainingEvaluation::ENTRY_ASSISTED_PAPER,
        );
    }

    /**
     * Keep partial answers under the current criteria version without
     * completing (0012-g). Unanswered questions stay null: a draft is an
     * unanswered form, so it is in no count and no mean. A completed
     * evaluation is not reopened into a draft here (contract: no silent
     * replacement of completed answers).
     *
     * @param  array<string, mixed>  $answers  rating => int|null, free text => string|null
     */
    public function saveDraft(User $actor, int $companyId, int $eventId, array $answers): TrainingEvaluation
    {
        [$tenant, $employee] = $this->scope($actor, $companyId);
        $participant = $this->participant($tenant, $companyId, $eventId, $employee);
        $event = $this->openAttendedEvent($tenant, $companyId, $participant);
        $version = self::currentCriteriaVersion();
        $answers = $this->answers($version, $answers);
        if ($this->existing($tenant, $companyId, $participant)?->status === TrainingEvaluationStatus::Completed) {
            throw new InvalidTrainingEvaluationException('This evaluation is already completed. Revise and submit it again rather than saving a draft.');
        }

        return $this->write($tenant, $companyId, $participant, $event, $version, $answers, TrainingEvaluationStatus::Draft, $actor);
    }

    /**
     * Complete under the current criteria version: every mandatory question
     * of that version must be answered, and the refusal names the missing
     * ones so the row stays as it was (draft or absent) until it is.
     *
     * @param  array<string, mixed>  $answers  rating => int|null, free text => string|null
     */
    public function complete(User $actor, int $companyId, int $eventId, array $answers): TrainingEvaluation
    {
        [$tenant, $employee] = $this->scope($actor, $companyId);
        $participant = $this->participant($tenant, $companyId, $eventId, $employee);
        $event = $this->openAttendedEvent($tenant, $companyId, $participant);
        $version = self::currentCriteriaVersion();
        $answers = $this->answers($version, $answers);
        $this->requireMandatory($version, $answers);

        return $this->write($tenant, $companyId, $participant, $event, $version, $answers, TrainingEvaluationStatus::Completed, $actor);
    }

    public static function currentCriteriaVersion(): string
    {
        $version = config('people-training.evaluation.current_criteria_version');
        if (! is_string($version) || $version === '') {
            throw new InvalidTrainingEvaluationException('No current evaluation criteria version is configured.');
        }

        return $version;
    }

    /**
     * The question set of one stored criteria version. A row is read against
     * the version it stores, so an unknown version is refused rather than
     * silently read as the current one.
     *
     * @return array{ratings: list<string>, free_text: list<string>, mandatory: list<string>}
     */
    public static function criteria(string $version): array
    {
        // Indexed, not dotted: a version string such as 0012-g.v1 holds a
        // dot, which config() would read as a path segment.
        $versions = config('people-training.evaluation.criteria_versions');
        $criteria = is_array($versions) ? ($versions[$version] ?? null) : null;
        if (! is_array($criteria) || ! is_array($criteria['ratings'] ?? null) || ! is_array($criteria['free_text'] ?? null) || ! is_array($criteria['mandatory'] ?? null)) {
            throw new InvalidTrainingEvaluationException("Evaluation criteria version {$version} is not configured.");
        }

        return $criteria;
    }

    /**
     * Every mandatory question of the version must be answered; the refusal
     * names the missing ones so the row stays as it was (draft or absent).
     *
     * @param  array<string, int|string|null>  $answers
     */
    private function requireMandatory(string $version, array $answers): void
    {
        $missing = array_values(array_filter(
            self::criteria($version)['mandatory'],
            static fn (string $key): bool => $answers[$key] === null,
        ));
        if ($missing !== []) {
            throw new InvalidTrainingEvaluationException(
                'Answer the mandatory questions before submitting: '.implode(', ', $missing).'.',
            );
        }
    }

    /**
     * Every question of the version, answered or null; a rating is 1-5 or
     * null (zero is outside the scale, not "unanswered"), free text is
     * trimmed and capped, and a key outside the version is refused.
     *
     * @param  array<string, mixed>  $answers
     * @return array<string, int|string|null>
     */
    private function answers(string $version, array $answers): array
    {
        $criteria = self::criteria($version);
        $unknown = array_diff(array_keys($answers), $criteria['ratings'], $criteria['free_text']);
        if ($unknown !== []) {
            throw new InvalidTrainingEvaluationException('The evaluation criteria do not match this form. Reload the page and try again.');
        }
        $clean = [];
        foreach ($criteria['ratings'] as $key) {
            $rating = $answers[$key] ?? null;
            if ($rating !== null && (! is_int($rating) || $rating < 1 || $rating > 5)) {
                throw new InvalidTrainingEvaluationException('Rate every evaluation item from 1 to 5.');
            }
            $clean[$key] = $rating;
        }
        foreach ($criteria['free_text'] as $key) {
            $clean[$key] = $this->text($key, $answers[$key] ?? null);
        }

        return $clean;
    }

    /** @param array<string, int|string|null> $answers */
    private function write(int $tenant, int $companyId, TrainingParticipant $participant, TrainingEvent $event, string $version, array $answers, TrainingEvaluationStatus $status, User $actor, ?string $notes = null, string $entrySource = TrainingEvaluation::ENTRY_SELF): TrainingEvaluation
    {
        // Its own transaction: the (tenant, participant) unique key can be
        // raced by a self-submission, and a refused insert must not poison
        // an outer transaction.
        return DB::transaction(fn (): TrainingEvaluation => TrainingEvaluation::query()->updateOrCreate([
            'tenant_id' => $tenant,
            'company_entity_id' => $companyId,
            'participant_id' => (int) $participant->id,
        ], [
            'event_id' => (int) $event->id,
            'employee_subject_id' => (string) $participant->employee_subject_id,
            'criteria_version' => $version,
            ...$answers,
            'notes' => $notes,
            'status' => $status,
            'due_on' => $event->ends_at->addDays(14)->toDateString(),
            'completed_at' => $status === TrainingEvaluationStatus::Completed ? now() : null,
            'submitted_by_user_id' => (int) $actor->getKey(),
            'entry_source' => $entrySource,
        ]));
    }

    private function existing(int $tenant, int $companyId, TrainingParticipant $participant): ?TrainingEvaluation
    {
        return TrainingEvaluation::query()->forCompany($tenant, $companyId)
            ->where('participant_id', (int) $participant->id)->first();
    }

    /**
     * The assisted path is HR's, resolved through SkillAudience so the module
     * keeps one audience engine; the participant is named, not derived from the
     * actor's own employee binding, which is the whole difference from scope().
     */
    private function hrScope(User $actor, int $companyId): int
    {
        $tenant = $this->tenancy->currentTenantId();
        $currentActor = $actor->exists ? User::query()->find($actor->getKey()) : null;
        if ($tenant === null || $currentActor === null || $currentActor->getCompanyId() !== $actor->getCompanyId()
            || (int) $currentActor->tenant_id !== $tenant || ! $this->companies->mayActFor($actor, $companyId)) {
            $this->deny();
        }
        if (! in_array(SkillAudience::HR, $this->audiences->authorizeAudience($actor, self::ASSIGN), true)) {
            $this->deny();
        }

        return $tenant;
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

        $presentParticipantIds = TrainingParticipationFact::query()->forCompany($tenant, $companyId)->current()
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
        $attended = TrainingParticipationFact::query()->forCompany($tenant, $companyId)->current()
            ->where('participant_id', $participant->id)
            ->where('attendance', AttendanceStatus::Present->value)->exists();
        if (! $attended) {
            throw new InvalidTrainingEvaluationException('An evaluation is available only for training you attended.');
        }

        return TrainingEvent::query()->forCompany($tenant, $companyId)
            ->whereKey($participant->event_id)->first() ?? $this->deny();
    }

    private function openAttendedEvent(int $tenant, int $companyId, TrainingParticipant $participant): TrainingEvent
    {
        $event = $this->attendedEvent($tenant, $companyId, $participant);
        if (now()->isAfter($event->ends_at->addDays(14))) {
            throw new InvalidTrainingEvaluationException('This evaluation window has closed. Ask HR if a traceable correction is needed.');
        }

        return $event;
    }

    private function text(string $key, mixed $text): ?string
    {
        if ($text !== null && ! is_string($text)) {
            throw new InvalidTrainingEvaluationException('The evaluation criteria do not match this form. Reload the page and try again.');
        }
        $text = trim((string) $text);
        if (mb_strlen($text) > 2000) {
            throw new InvalidTrainingEvaluationException(sprintf('Keep %s to 2,000 characters or fewer.', str_replace('_', ' ', $key)));
        }

        return $text === '' ? null : $text;
    }

    private function deny(): never
    {
        throw new InvalidTrainingEvaluationException('The training evaluation is unavailable in the current scope.');
    }
}
