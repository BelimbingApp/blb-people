<?php

namespace App\Domains\People\Training\Livewire\Evaluations;

use App\Base\Authz\Exceptions\AuthorizationDeniedException;
use App\Base\Tenancy\Contracts\TenantContext;
use App\Core\Employee\Models\Employee;
use App\Domains\People\Skills\Services\SkillAudience;
use App\Domains\People\Training\Enums\AttendanceStatus;
use App\Domains\People\Training\Enums\TrainingEvaluationStatus;
use App\Domains\People\Training\Models\TrainingEvaluation;
use App\Domains\People\Training\Models\TrainingEvent;
use App\Domains\People\Training\Models\TrainingParticipant;
use App\Domains\People\Training\Models\TrainingParticipationFact;
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
 *
 * Only completed evaluations count (0012-e): a draft is an unanswered form,
 * so it is in neither the submitted count nor a mean, and the drill-down
 * that opens a count or a mean lists exactly the rows that produced it. A
 * departmental reader gets the same rows without the free-text columns,
 * because the reader never selected them.
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

    /** @var list<string> the free-text columns, shown when the reader selected them */
    private const COMMENT_COLUMNS = [
        'most_useful_learning',
        'application_commitment',
        'support_needed',
        'recommendation',
        'issues_or_improvements',
    ];

    public ?int $openEventId = null;

    /** One of the rating criteria, or null for the completion drill-down. */
    public ?string $openCriterion = null;

    public function mount(): void
    {
        $this->authorizeView();
    }

    /** Open the evaluations behind an event's completion count. */
    public function openCompletion(int $eventId): void
    {
        $this->authorizeView();
        $this->openEventId = $eventId;
        $this->openCriterion = null;
    }

    /** Open the evaluations behind one rating mean of an event. */
    public function openMean(int $eventId, string $criterion): void
    {
        $this->authorizeView();
        abort_unless(in_array($criterion, self::RATINGS, true), 404);
        $this->openEventId = $eventId;
        $this->openCriterion = $criterion;
    }

    public function closeDrillDown(): void
    {
        $this->openEventId = null;
        $this->openCriterion = null;
    }

    public function render(TrainingEvaluationReader $reader): View
    {
        $this->authorizeView();
        $actor = Auth::user();
        $companyId = (int) $actor->company_id;

        return view('people::livewire.evaluations.index', [
            'events' => $this->events($reader, $companyId),
            'drillDown' => $this->drillDown($reader, $companyId),
        ]);
    }

    /**
     * The rows behind the open count or mean: the completed evaluations of
     * that event, restricted to those with a value for the open criterion.
     * The event is looked up inside the company; an id from elsewhere is a
     * 404, not an empty list that reads as "nothing to show".
     *
     * @return array{event_id: int, title: string, criterion: string|null, rows: list<array<string, mixed>>, mean: float|null, comment_columns: list<string>}|null
     */
    private function drillDown(TrainingEvaluationReader $reader, int $companyId): ?array
    {
        if ($this->openEventId === null) {
            return null;
        }
        $criterion = $this->openCriterion;
        abort_unless($criterion === null || in_array($criterion, self::RATINGS, true), 404);
        $event = TrainingEvent::query()->forCompany($this->tenantOf($reader), $companyId)->whereKey($this->openEventId)->first();
        abort_if($event === null, 404);

        $rows = $this->completed($reader->visibleTo(Auth::user(), $companyId)->where('event_id', $event->id)->orderBy('completed_at')->get());
        if ($criterion !== null) {
            $rows = $rows->filter(static fn (TrainingEvaluation $e): bool => $e->{$criterion} !== null)->values();
        }
        $names = $this->participantNames($this->tenantOf($reader), $companyId, [(int) $event->id]);
        $commentColumns = $rows->isEmpty() ? [] : array_values(array_filter(self::COMMENT_COLUMNS, static fn (string $c): bool => array_key_exists($c, $rows->first()->getAttributes())));

        return [
            'event_id' => (int) $event->id,
            'title' => (string) $event->course_title_snapshot,
            'criterion' => $criterion,
            'mean' => $criterion === null ? null : $this->means($rows)[$criterion],
            'comment_columns' => $commentColumns,
            'rows' => $rows->map(function (TrainingEvaluation $e) use ($names, $commentColumns): array {
                $row = [
                    'id' => (int) $e->id,
                    'participant' => (string) ($names[(string) $e->employee_subject_id] ?? __('Unknown participant')),
                    'submitted_on' => (string) $e->completed_at?->toDateString(),
                    'entry_source' => (string) $e->entry_source,
                ];
                foreach (self::RATINGS as $rating) {
                    $row[$rating] = $e->{$rating} === null ? null : (int) $e->{$rating};
                }
                foreach ($commentColumns as $column) {
                    $row[$column] = trim((string) $e->{$column});
                }

                return $row;
            })->values()->all(),
        ];
    }

    /** A draft is an unanswered form: it is in no count, no mean and no drill-down. */
    private function completed(Collection $rows): Collection
    {
        return $rows->filter(static fn (TrainingEvaluation $e): bool => $e->status === TrainingEvaluationStatus::Completed)->values();
    }

    /**
     * @return list<array{event_id: int, title: string, attended: int, submitted: int, response_rate: int|null, means: array<string, float|null>, comments: list<array{participant: string, comment: string}>}>
     */
    private function events(TrainingEvaluationReader $reader, int $companyId): array
    {
        $evaluations = $this->completed($reader->visibleTo(Auth::user(), $companyId)->get())->groupBy('event_id');
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
        return TrainingParticipationFact::query()->forCompany($tenantId, $companyId)->current()
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
