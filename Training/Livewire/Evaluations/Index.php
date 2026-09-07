<?php

namespace App\Domains\People\Training\Livewire\Evaluations;

use App\Base\Authz\Exceptions\AuthorizationDeniedException;
use App\Base\Tenancy\Contracts\TenantContext;
use App\Core\Employee\Models\Employee;
use App\Domains\People\Skills\Services\SkillAudience;
use App\Domains\People\Training\Enums\AttendanceStatus;
use App\Domains\People\Training\Enums\TrainingEvaluationStatus;
use App\Domains\People\Training\Exceptions\InvalidTrainingEvaluationException;
use App\Domains\People\Training\Models\TrainingEvaluation;
use App\Domains\People\Training\Models\TrainingEvent;
use App\Domains\People\Training\Models\TrainingParticipant;
use App\Domains\People\Training\Models\TrainingParticipationFact;
use App\Domains\People\Training\Services\TrainingEvaluationReader;
use App\Domains\People\Training\Services\TrainingEvaluationSubmissionStore;
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
 *
 * HR can also key in a completed paper form for an attended participant
 * (0012-f). The form only lists participants without a completed evaluation:
 * the store refuses the rest anyway, and the page should not offer what it
 * knows will be refused. The row it writes names HR as the entering actor and
 * says the answers arrived on paper, which both this page and the employee's
 * own view show.
 */
final class Index extends Component
{
    public const VIEW_CAPABILITY = 'people.training.evaluation-aggregate.view';

    public mixed $paperParticipantId = null;

    public mixed $paperRelevance = null;

    public mixed $paperTrainerEffectiveness = null;

    public mixed $paperMaterialsExercises = null;

    public mixed $paperPaceDuration = null;

    public mixed $paperPracticalUsefulness = null;

    public string $paperReference = '';

    public string $paperComment = '';

    /** @var list<string> */
    private const RATINGS = [
        'relevance',
        'trainer_effectiveness',
        'materials_exercises',
        'pace_duration',
        'practical_usefulness',
    ];

    public function mount(): void
    {
        $this->authorizeView();
    }

    public function render(TrainingEvaluationReader $reader): View
    {
        $this->authorizeView();
        $actor = Auth::user();
        $companyId = (int) $actor->company_id;

        return view('people::livewire.evaluations.index', [
            'events' => $this->events($reader, $companyId),
            'paperCandidates' => $this->paperCandidates($this->tenantOf($reader), $companyId),
        ]);
    }

    /**
     * Enter a completed paper evaluation for one attended participant.
     *
     * The rating rules are the store's; the component validates the same
     * bounds first only so the form shows a field-level message rather than a
     * page-level refusal for a blank select.
     */
    public function enterPaperEvaluation(): void
    {
        $this->authorizeView();
        $validated = $this->validate([
            'paperParticipantId' => ['required', 'integer'],
            'paperRelevance' => ['required', 'integer', 'between:1,5'],
            'paperTrainerEffectiveness' => ['required', 'integer', 'between:1,5'],
            'paperMaterialsExercises' => ['required', 'integer', 'between:1,5'],
            'paperPaceDuration' => ['required', 'integer', 'between:1,5'],
            'paperPracticalUsefulness' => ['required', 'integer', 'between:1,5'],
            'paperReference' => ['required', 'string', 'max:160'],
            'paperComment' => ['nullable', 'string', 'max:2000'],
        ]);

        try {
            app(TrainingEvaluationSubmissionStore::class)->submitAssisted(
                Auth::user(),
                (int) Auth::user()->company_id,
                (int) $validated['paperParticipantId'],
                [
                    'relevance' => (int) $validated['paperRelevance'],
                    'trainer_effectiveness' => (int) $validated['paperTrainerEffectiveness'],
                    'materials_exercises' => (int) $validated['paperMaterialsExercises'],
                    'pace_duration' => (int) $validated['paperPaceDuration'],
                    'practical_usefulness' => (int) $validated['paperPracticalUsefulness'],
                ],
                $validated['paperComment'],
                $validated['paperReference'],
            );
        } catch (AuthorizationDeniedException) {
            abort(403);
        } catch (InvalidTrainingEvaluationException $refusal) {
            $this->addError('paper', $refusal->getMessage());

            return;
        }

        $this->reset('paperParticipantId', 'paperRelevance', 'paperTrainerEffectiveness', 'paperMaterialsExercises', 'paperPaceDuration', 'paperPracticalUsefulness', 'paperReference', 'paperComment');
        session()->flash('training-evaluations-status', __('Paper evaluation entered. The record names you as the entering actor.'));
    }

    /**
     * Attended participants of ended events, still inside the 14-day window,
     * without a completed evaluation — the only rows assisted entry may write.
     *
     * @return list<array{participant_id: int, label: string}>
     */
    private function paperCandidates(int $tenantId, int $companyId): array
    {
        if (! app(SkillAudience::class)->mayAccess(Auth::user(), TrainingEvaluationSubmissionStore::ASSIGN)) {
            return [];
        }

        $events = TrainingEvent::query()->forCompany($tenantId, $companyId)
            ->where('ends_at', '<=', now())
            ->where('ends_at', '>=', now()->subDays(14))
            ->get()->keyBy('id');
        if ($events->isEmpty()) {
            return [];
        }

        $attended = TrainingParticipationFact::query()->forCompany($tenantId, $companyId)
            ->whereIn('event_id', $events->keys()->all())
            ->where('attendance', AttendanceStatus::Present->value)
            ->pluck('participant_id')->map(static fn ($id): int => (int) $id)->unique();
        $completed = TrainingEvaluation::query()->forCompany($tenantId, $companyId)
            ->whereIn('participant_id', $attended->all())
            ->where('status', TrainingEvaluationStatus::Completed->value)
            ->pluck('participant_id')->map(static fn ($id): int => (int) $id);
        $names = $this->participantNames($tenantId, $companyId, $events->keys()->all());

        return TrainingParticipant::query()->forCompany($tenantId, $companyId)
            ->whereIn('id', $attended->diff($completed)->all())
            ->orderBy('id')->get()
            ->map(static fn (TrainingParticipant $participant): array => [
                'participant_id' => (int) $participant->id,
                'label' => sprintf(
                    '%s — %s',
                    $names[(string) $participant->employee_subject_id] ?? __('Unknown participant'),
                    $events->get($participant->event_id)?->course_title_snapshot ?? __('Training event'),
                ),
            ])->values()->all();
    }

    /**
     * @return list<array{event_id: int, title: string, attended: int, submitted: int, response_rate: int|null, means: array<string, float|null>, paper_entries: int, comments: list<array{participant: string, comment: string, from_paper: bool}>}>
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

        return $events->map(function (TrainingEvent $event) use ($evaluations, $attended, $names): array {
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
                'paper_entries' => $rows->filter(static fn (TrainingEvaluation $evaluation): bool => $evaluation->enteredFromPaper())->count(),
                'comments' => $this->comments($rows, $names),
            ];
        })->values()->all();
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
     * @return list<array{participant: string, comment: string, from_paper: bool}>
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
                'from_paper' => $evaluation->enteredFromPaper(),
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

    private function authorizeView(): void
    {
        try {
            app(SkillAudience::class)->authorizeAudience(Auth::user(), self::VIEW_CAPABILITY);
        } catch (AuthorizationDeniedException) {
            abort(403);
        }
    }
}
