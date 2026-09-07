<?php

namespace App\Domains\People\Training\Livewire\Evaluations;

use App\Base\Authz\Contracts\AuthorizationService;
use App\Base\Authz\DTO\Actor;
use App\Base\Authz\Exceptions\AuthorizationDeniedException;
use App\Base\Tenancy\Contracts\TenantContext;
use App\Core\Employee\Models\Employee;
use App\Core\User\Models\User;
use App\Domains\People\Skills\Services\SkillAudience;
use App\Domains\People\Training\Enums\AttendanceStatus;
use App\Domains\People\Training\Exceptions\InvalidTrainingEvaluationException;
use App\Domains\People\Training\Models\TrainingEvaluation;
use App\Domains\People\Training\Models\TrainingEvaluationFollowup;
use App\Domains\People\Training\Models\TrainingEvent;
use App\Domains\People\Training\Models\TrainingParticipant;
use App\Domains\People\Training\Models\TrainingParticipationFact;
use App\Domains\People\Training\Services\TrainingEvaluationFollowupStore;
use App\Domains\People\Training\Services\TrainingEvaluationReader;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

/**
 * Response rate, rating means and comments per training event.
 *
 * Evaluations are read through {@see TrainingEvaluationReader::visibleTo()}
 * rather than queried here. That reader already applies the audience scope and
 * drops the free-text columns for a departmental reader by not selecting them,
 * and an aggregate page is exactly the sort of thing that becomes the way round
 * such a rule if it goes to the table itself.
 *
 * The denominator is attended participants, not everyone invited. Somebody who
 * never turned up was never asked to evaluate, and counting them would read as
 * a failure to respond.
 */
final class Index extends Component
{
    public const VIEW_CAPABILITY = 'people.training.evaluation-aggregate.view';

    /** @var list<string> */
    private const RATINGS = [
        'relevance',
        'trainer_effectiveness',
        'materials_exercises',
        'pace_duration',
        'practical_usefulness',
    ];

    /** @var array<int, string> action notes keyed by follow-up id */
    public array $followupNotes = [];

    public function mount(): void
    {
        $this->authorizeView();
    }

    public function openFollowup(int $evaluationId, string $kind): void
    {
        $this->authorizeFollowup();
        try {
            app(TrainingEvaluationFollowupStore::class)->open($this->user(), $this->companyId(), $evaluationId, $kind);
        } catch (InvalidTrainingEvaluationException $refusal) {
            $this->addError('followup', $refusal->getMessage());
        }
    }

    public function progressFollowup(int $followupId): void
    {
        $this->authorizeFollowup();
        try {
            app(TrainingEvaluationFollowupStore::class)->progress(
                $this->user(), $this->companyId(), $followupId, $this->followupNotes[$followupId] ?? null,
            );
        } catch (InvalidTrainingEvaluationException $refusal) {
            $this->addError('followup', $refusal->getMessage());
        }
    }

    public function closeFollowup(int $followupId): void
    {
        $this->authorizeFollowup();
        try {
            app(TrainingEvaluationFollowupStore::class)->close(
                $this->user(), $this->companyId(), $followupId, $this->followupNotes[$followupId] ?? null,
            );
        } catch (InvalidTrainingEvaluationException $refusal) {
            $this->addError('followup', $refusal->getMessage());
        }
    }

    public function render(TrainingEvaluationReader $reader): View
    {
        $this->authorizeView();
        $companyId = $this->companyId();

        return view('people::livewire.evaluations.index', [
            'events' => $this->events($reader, $companyId),
            'canManageFollowups' => $this->canManageFollowups(),
        ]);
    }

    /**
     * @return list<array{event_id: int, title: string, attended: int, submitted: int, response_rate: int|null, means: array<string, float|null>, comments: list<array{participant: string, comment: string}>, flagged: list<array{evaluation_id: int, participant: string, support: array{id: int, status: string}|null, provider: array{id: int, status: string}|null}>}>
     */
    private function events(TrainingEvaluationReader $reader, int $companyId): array
    {
        $evaluations = $reader->visibleTo(Auth::user(), $companyId)->get()->groupBy('event_id');
        $events = TrainingEvent::query()->forCompany($this->tenantOf($reader), $companyId)
            ->orderByDesc('starts_at')->get();

        if ($events->isEmpty()) {
            return [];
        }

        $attended = $this->attendedCounts($this->tenantOf($reader), $companyId, $events->pluck('id')->all());
        $names = $this->participantNames($this->tenantOf($reader), $companyId, $events->pluck('id')->all());
        $followups = $this->followupStates($this->tenantOf($reader), $companyId, $evaluations->flatten()->all());

        return $events->map(function (TrainingEvent $event) use ($evaluations, $attended, $names, $followups): array {
            $rows = $evaluations->get($event->id, collect());
            $attendedCount = (int) ($attended[$event->id] ?? 0);

            return [
                'event_id' => (int) $event->id,
                'title' => (string) $event->course_title_snapshot,
                'attended' => $attendedCount,
                'submitted' => $rows->count(),
                // No attendance is not a nought-percent response. Nobody was
                // asked, so there is no rate to report.
                'response_rate' => $attendedCount === 0 ? null : (int) round($rows->count() / $attendedCount * 100),
                'means' => $this->means($rows),
                'comments' => $this->comments($rows, $names),
                'flagged' => $this->flagged($rows, $names, $followups),
            ];
        })->values()->all();
    }

    /**
     * Evaluations the viewer can actually see carrying a support request or a
     * provider concern. Redacted rows never selected the free-text columns,
     * so they cannot flag: presence in the attributes is the visibility
     * proof, and absence (a departmental reader) stays absent here too.
     *
     * @param  iterable<TrainingEvaluation>  $rows
     * @param  array<string, string>  $names
     * @param  array<int, array{support_request: array{id: int, status: string}|null, provider_concern: array{id: int, status: string}|null}>  $followups
     * @return list<array{evaluation_id: int, participant: string, support: array{id: int, status: string}|null, provider: array{id: int, status: string}|null}>
     */
    private function flagged(iterable $rows, array $names, array $followups): array
    {
        $flagged = [];
        foreach ($rows as $evaluation) {
            $attributes = $evaluation->getAttributes();
            $hasSupport = array_key_exists('support_needed', $attributes) && trim((string) $evaluation->support_needed) !== '';
            $hasIssues = array_key_exists('issues_or_improvements', $attributes) && trim((string) $evaluation->issues_or_improvements) !== '';
            if (! $hasSupport && ! $hasIssues) {
                continue;
            }

            $states = $followups[(int) $evaluation->id] ?? [
                'support_request' => null,
                'provider_concern' => null,
            ];

            $flagged[] = [
                'evaluation_id' => (int) $evaluation->id,
                'participant' => (string) ($names[(string) $evaluation->employee_subject_id] ?? __('Unknown participant')),
                'support' => $states['support_request'],
                'provider' => $states['provider_concern'],
            ];
        }

        return $flagged;
    }

    /**
     * Latest follow-up per evaluation per kind: an open or in-progress row is
     * the live state, otherwise the last closed row, otherwise nothing to act
     * on yet.
     *
     * @param  list<TrainingEvaluation>  $evaluations
     * @return array<int, array{support_request: array{id: int, status: string}|null, provider_concern: array{id: int, status: string}|null}>
     */
    private function followupStates(int $tenantId, int $companyId, array $evaluations): array
    {
        $ids = array_map(static fn (TrainingEvaluation $evaluation): int => (int) $evaluation->id, $evaluations);
        if ($ids === []) {
            return [];
        }

        $states = [];
        foreach ($ids as $id) {
            $states[$id] = ['support_request' => null, 'provider_concern' => null];
        }
        $rows = TrainingEvaluationFollowup::query()->forCompany($tenantId, $companyId)
            ->whereIn('evaluation_id', $ids)->orderBy('id')->get();
        foreach ($rows as $row) {
            $states[(int) $row->evaluation_id][$row->kind->value] = [
                'id' => (int) $row->id,
                'status' => $row->status->value,
            ];
        }

        return $states;
    }

    /** @return array<string, float|null> */
    private function means(Collection $rows): array
    {
        $means = [];
        foreach (self::RATINGS as $rating) {
            $values = $rows->pluck($rating)->filter(static fn (mixed $value): bool => $value !== null);
            $means[$rating] = $values->isEmpty() ? null : round((float) $values->avg(), 2);
        }

        return $means;
    }

    /**
     * @param  array<string, string>  $names
     * @return list<array{participant: string, comment: string}>
     */
    private function comments(Collection $rows, array $names): array
    {
        return $rows
            // A redacted read never selected the column, so it is absent from
            // the model rather than null. Asking getAttributes() keeps the two
            // cases apart instead of treating "not allowed" as "left blank".
            ->filter(static fn (TrainingEvaluation $evaluation): bool => array_key_exists('issues_or_improvements', $evaluation->getAttributes())
                && trim((string) $evaluation->issues_or_improvements) !== '')
            ->map(static fn (TrainingEvaluation $evaluation): array => [
                'participant' => (string) ($names[(string) $evaluation->employee_subject_id] ?? __('Unknown participant')),
                'comment' => trim((string) $evaluation->issues_or_improvements),
            ])
            ->values()
            ->all();
    }

    /**
     * @param  list<int>  $eventIds
     * @return array<int, int>
     */
    private function attendedCounts(int $tenantId, int $companyId, array $eventIds): array
    {
        return TrainingParticipationFact::query()->forCompany($tenantId, $companyId)
            ->whereIn('event_id', $eventIds)
            ->where('attendance', AttendanceStatus::Present->value)
            ->get()
            ->groupBy('event_id')
            ->map(static fn ($facts): int => $facts->pluck('participant_id')->unique()->count())
            ->all();
    }

    /**
     * @param  list<int>  $eventIds
     * @return array<string, string>
     */
    private function participantNames(int $tenantId, int $companyId, array $eventIds): array
    {
        $subjects = TrainingParticipant::query()->forCompany($tenantId, $companyId)
            ->whereIn('event_id', $eventIds)->pluck('employee_subject_id')->unique();

        return Employee::query()->where('company_id', $companyId)
            ->whereIn('id', $subjects->map(static fn (string $id): int => (int) $id))
            ->pluck('full_name', 'id')
            ->mapWithKeys(static fn (string $name, int $id): array => [(string) $id => $name])
            ->all();
    }

    private function tenantOf(TrainingEvaluationReader $reader): int
    {
        return (int) app(TenantContext::class)->requireTenantId();
    }

    private function authorizeFollowup(): void
    {
        try {
            app(AuthorizationService::class)->authorize(
                Actor::forUser($this->user()), TrainingEvaluationFollowupStore::MANAGE,
            );
        } catch (AuthorizationDeniedException) {
            abort(403);
        }
    }

    private function canManageFollowups(): bool
    {
        try {
            app(AuthorizationService::class)->authorize(
                Actor::forUser($this->user()), TrainingEvaluationFollowupStore::MANAGE,
            );

            return true;
        } catch (AuthorizationDeniedException) {
            return false;
        }
    }

    private function user(): User
    {
        $user = Auth::user();
        abort_unless($user instanceof User, 403);

        return $user;
    }

    private function companyId(): int
    {
        return (int) $this->user()->company_id;
    }

    private function authorizeView(): void
    {
        try {
            app(SkillAudience::class)->authorizeAudience(Auth::user(), self::VIEW_CAPABILITY);
        } catch (AuthorizationDeniedException) {
            abort(403);
        }
    }
}
