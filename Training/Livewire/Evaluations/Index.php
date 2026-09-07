<?php

namespace App\Domains\People\Training\Livewire\Evaluations;

use App\Base\Authz\Exceptions\AuthorizationDeniedException;
use App\Base\Tenancy\Contracts\TenantContext;
use App\Core\Employee\Models\Employee;
use App\Domains\People\Skills\Services\SkillAudience;
use App\Domains\People\Training\Enums\AttendanceStatus;
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
        ]);
    }

    /**
     * @return list<array{event_id: int, title: string, attended: int, submitted: int, response_rate: int|null, means: array<string, float|null>, comments: list<array{participant: string, comment: string}>}>
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
