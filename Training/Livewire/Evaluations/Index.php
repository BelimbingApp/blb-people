<?php

namespace App\Domains\People\Training\Livewire\Evaluations;

use App\Base\Authz\Contracts\AuthorizationService;
use App\Base\Authz\DTO\Actor;
use App\Base\Authz\Exceptions\AuthorizationDeniedException;
use App\Base\Tenancy\Contracts\TenantContext;
use App\Core\Employee\Models\Employee;
use App\Core\User\Models\User;
use App\Domains\People\Skills\Services\SkillAudience;
use App\Domains\People\Skills\Services\WorkforceSubjects;
use App\Domains\People\Training\Data\DueEvaluation;
use App\Domains\People\Training\Enums\AttendanceStatus;
use App\Domains\People\Training\Enums\TrainingEvaluationStatus;
use App\Domains\People\Training\Exceptions\InvalidTrainingEvaluationException;
use App\Domains\People\Training\Models\TrainingEvaluation;
use App\Domains\People\Training\Models\TrainingEvaluationFollowup;
use App\Domains\People\Training\Models\TrainingEvent;
use App\Domains\People\Training\Models\TrainingParticipant;
use App\Domains\People\Training\Models\TrainingParticipationFact;
use App\Domains\People\Training\Services\TrainingEvaluationFollowupStore;
use App\Domains\People\Training\Services\TrainingEvaluationReader;
use App\Domains\People\Training\Services\TrainingEvaluationReminders;
use App\Domains\People\Training\Services\TrainingEvaluationSubmissionStore;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Url;
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

    /** @var array<string, mixed> rating column => 1-5, the current criteria version's ratings */
    public array $paperRatings = [];

    /** @var array<string, mixed> free-text column => answer, the current criteria version's free text */
    public array $paperText = [];

    public string $paperReference = '';

    /** @var array<int, string> action notes keyed by follow-up id */
    public array $followupNotes = [];

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

    /** Organization-unit stable id, or empty for the whole company (0012-d); URL-bound for the KPI drill-down (#389). */
    #[Url]
    public string $department = '';

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
        abort_unless(in_array($criterion, TrainingEvaluationReader::RATINGS, true), 404);
        $this->openEventId = $eventId;
        $this->openCriterion = $criterion;
    }

    public function closeDrillDown(): void
    {
        $this->openEventId = null;
        $this->openCriterion = null;
    }

    public function render(
        TrainingEvaluationReader $reader,
        TrainingEvaluationReminders $reminders,
        WorkforceSubjects $subjects,
    ): View {
        $this->authorizeView();
        $companyId = $this->companyId();
        $departments = $this->departmentNames($subjects, $companyId);
        // One resolution of the department filter, used by every panel on the
        // page. Null means "the whole company"; a list means exactly the
        // employees of the selected organisation unit.
        $scope = $this->departmentEmployeeSubjectIds($subjects, $companyId, $departments);

        return view('people::livewire.evaluations.index', [
            'events' => $this->events($reader, $companyId, $scope),
            'paperCandidates' => $this->paperCandidates($this->tenantOf($reader), $companyId),
            'paperCriteria' => self::paperCriteria(),
            'canManageFollowups' => $this->canManageFollowups(),
            'drillDown' => $this->drillDown($reader, $companyId, $scope),
            'departments' => $departments,
            'overdue' => $this->overdue($reminders, $subjects, $companyId, $departments),
        ]);
    }

    /**
     * Enter a completed paper evaluation for one attended participant.
     *
     * The questions are the current criteria version's, read from the store
     * so the form and the row it writes never disagree. The rating rules are
     * the store's; the component validates the same bounds first only so the
     * form shows a field-level message rather than a page-level refusal for a
     * blank select. Every mandatory question of the version is required
     * here for the same reason.
     */
    public function enterPaperEvaluation(): void
    {
        $this->authorizeView();
        $criteria = self::paperCriteria();
        $rules = [
            'paperParticipantId' => ['required', 'integer'],
            'paperReference' => ['required', 'string', 'max:160'],
        ];
        foreach ($criteria['ratings'] as $column) {
            $rules['paperRatings.'.$column] = [in_array($column, $criteria['mandatory'], true) ? 'required' : 'nullable', 'integer', 'between:1,5'];
        }
        foreach ($criteria['free_text'] as $column) {
            $rules['paperText.'.$column] = [in_array($column, $criteria['mandatory'], true) ? 'required' : 'nullable', 'string', 'max:2000'];
        }
        $validated = $this->validate($rules);

        $answers = [];
        foreach ($criteria['ratings'] as $column) {
            $value = $validated['paperRatings'][$column] ?? null;
            $answers[$column] = $value === null || $value === '' ? null : (int) $value;
        }
        foreach ($criteria['free_text'] as $column) {
            $answers[$column] = $validated['paperText'][$column] ?? null;
        }

        try {
            app(TrainingEvaluationSubmissionStore::class)->submitAssisted(
                $this->user(),
                $this->companyId(),
                (int) $validated['paperParticipantId'],
                $answers,
                $validated['paperReference'],
            );
        } catch (AuthorizationDeniedException) {
            abort(403);
        } catch (InvalidTrainingEvaluationException $refusal) {
            $this->addError('paper', $refusal->getMessage());

            return;
        }

        $this->reset('paperParticipantId', 'paperRatings', 'paperText', 'paperReference');
        session()->flash('training-evaluations-status', __('Paper evaluation entered. The record names you as the entering actor.'));
    }

    /**
     * The current criteria version's questions, as the paper form presents them.
     *
     * @return array{version: string, ratings: list<string>, free_text: list<string>, mandatory: list<string>}
     */
    private static function paperCriteria(): array
    {
        $version = TrainingEvaluationSubmissionStore::currentCriteriaVersion();

        return ['version' => $version, ...TrainingEvaluationSubmissionStore::criteria($version)];
    }

    /**
     * Attended participants of ended events, still inside the 14-day window,
     * without a completed evaluation — the only rows assisted entry may write.
     *
     * @return list<array{participant_id: int, label: string}>
     */
    private function paperCandidates(int $tenantId, int $companyId): array
    {
        if (! app(SkillAudience::class)->mayAccess($this->user(), TrainingEvaluationSubmissionStore::ASSIGN)) {
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
     * The overdue drill-down (0012-d) is the same rule the reminder command
     * runs, read through {@see TrainingEvaluationReminders::overdue()}, so the
     * number on the dashboard and the participants the command chases cannot
     * drift apart. The count is the number of rows shown under the same
     * filter, never a company total the filter leaves behind.
     *
     * @param  array<string, string>  $departments
     * @return array{count: int, rows: list<array{participant: string, department: string, event: string, due_on: string, days_overdue: int}>}
     */
    private function overdue(TrainingEvaluationReminders $reminders, WorkforceSubjects $subjects, int $companyId, array $departments): array
    {
        $tenantId = (int) app(TenantContext::class)->requireTenantId();
        // Same audience the ratings use: a HOD with the aggregate capability
        // sees their own people overdue, not the whole company's.
        $visible = app(SkillAudience::class)->visibleEmployeeEntityIdsFor(Auth::user(), $companyId, self::VIEW_CAPABILITY);

        $overdue = $reminders->overdue($tenantId, $companyId);

        // Named the way the rest of this page names participants, from the
        // employee record; the workforce seam is read only for the unit.
        $names = Employee::query()->where('company_id', $companyId)
            ->whereIn('id', array_map(static fn (DueEvaluation $row): int => $row->employeeEntityId, $overdue))
            ->pluck('full_name', 'id')
            ->all();
        $units = [];
        foreach ($subjects->employees($companyId) as $employee) {
            $units[(int) $employee->reference->externalId] = $employee->organizationReference?->externalId ?? '';
        }

        $rows = collect($overdue)
            ->filter(fn (DueEvaluation $row): bool => in_array($row->employeeEntityId, $visible, true)
                && ($this->department === '' || ($units[$row->employeeEntityId] ?? '') === $this->department))
            ->map(static fn (DueEvaluation $row): array => [
                'participant' => (string) ($names[$row->employeeEntityId] ?? __('Unknown participant')),
                'department' => $departments[$units[$row->employeeEntityId] ?? ''] ?? '',
                'event' => $row->eventTitle,
                'due_on' => $row->dueOn->toDateString(),
                'days_overdue' => $row->daysOverdue,
            ])
            ->sortBy([['days_overdue', 'desc'], ['participant', 'asc']])
            ->values()
            ->all();

        return ['count' => count($rows), 'rows' => $rows];
    }

    /**
     * The employees of the selected organisation unit, as the string subject
     * ids the training tables store, or null for the whole company.
     *
     * The department filter has to reach the events table too, not only the
     * overdue list: Attended, the response rate and the criterion means are
     * all read from that table, and a drill-down that lands here with a
     * department must show the rows behind the department's number (#389).
     *
     * @param  array<string, string>  $departments
     * @return list<string>|null
     */
    private function departmentEmployeeSubjectIds(WorkforceSubjects $subjects, int $companyId, array $departments): ?array
    {
        if ($this->department === '' || ! array_key_exists($this->department, $departments)) {
            return null;
        }

        $ids = [];
        foreach ($subjects->employees($companyId) as $employee) {
            if (($employee->organizationReference?->externalId ?? '') === $this->department) {
                $ids[] = (string) $employee->reference->externalId;
            }
        }

        return $ids;
    }

    /**
     * @param  Collection<int, TrainingEvaluation>  $rows
     * @param  list<string>|null  $scope
     * @return Collection<int, TrainingEvaluation>
     */
    private function inScope(Collection $rows, ?array $scope): Collection
    {
        if ($scope === null) {
            return $rows;
        }

        return $rows->filter(static fn (TrainingEvaluation $evaluation): bool => in_array((string) $evaluation->employee_subject_id, $scope, true))->values();
    }

    /** @return array<string, string> organization-unit stable id => name */
    private function departmentNames(WorkforceSubjects $subjects, int $companyId): array
    {
        $names = [];
        foreach ($subjects->organizationUnits($companyId) as $unit) {
            $names[$unit->reference->externalId] = $unit->name;
        }

        return $names;
    }

    /**
     * The rows behind the open count or mean: the completed evaluations of
     * that event, restricted to those with a value for the open criterion.
     * The event is looked up inside the company; an id from elsewhere is a
     * 404, not an empty list that reads as "nothing to show".
     *
     * @return array{event_id: int, title: string, criterion: string|null, rows: list<array<string, mixed>>, mean: float|null, comment_columns: list<string>}|null
     */
    private function drillDown(TrainingEvaluationReader $reader, int $companyId, ?array $scope = null): ?array
    {
        if ($this->openEventId === null) {
            return null;
        }
        $criterion = $this->openCriterion;
        abort_unless($criterion === null || in_array($criterion, TrainingEvaluationReader::RATINGS, true), 404);
        $event = TrainingEvent::query()->forCompany($this->tenantOf($reader), $companyId)->whereKey($this->openEventId)->first();
        abort_if($event === null, 404);

        $rows = $this->completed($reader->visibleTo(Auth::user(), $companyId)->where('event_id', $event->id)->orderBy('completed_at')->get());
        $rows = $this->inScope($rows, $scope);
        if ($criterion !== null) {
            $rows = $rows->filter(static fn (TrainingEvaluation $e): bool => $e->{$criterion} !== null)->values();
        }
        $names = $this->participantNames($this->tenantOf($reader), $companyId, [(int) $event->id]);
        $commentColumns = $rows->isEmpty() ? [] : array_values(array_filter(self::COMMENT_COLUMNS, static fn (string $c): bool => array_key_exists($c, $rows->first()->getAttributes())));

        return [
            'event_id' => (int) $event->id,
            'title' => (string) $event->course_title_snapshot,
            'criterion' => $criterion,
            'mean' => $criterion === null ? null : $reader->means($rows)[$criterion]['mean'],
            'comment_columns' => $commentColumns,
            'rows' => $rows->map(function (TrainingEvaluation $e) use ($names, $commentColumns): array {
                $row = [
                    'id' => (int) $e->id,
                    'participant' => (string) ($names[(string) $e->employee_subject_id] ?? __('Unknown participant')),
                    'submitted_on' => (string) $e->completed_at?->toDateString(),
                    'entry_source' => (string) $e->entry_source,
                ];
                foreach (TrainingEvaluationReader::RATINGS as $rating) {
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
     * @return list<array{event_id: int, title: string, attended: int, submitted: int, response_rate: int|null, means: array<string, float|null>, answered: array<string, int>, paper_entries: int, comments: list<array{participant: string, comment: string, from_paper: bool}>, flagged: list<array{evaluation_id: int, participant: string, support: array{id: int, status: string}|null, provider: array{id: int, status: string}|null}>}>
     */
    private function events(TrainingEvaluationReader $reader, int $companyId, ?array $scope = null): array
    {
        $evaluations = $this->inScope($this->completed($reader->visibleTo(Auth::user(), $companyId)->get()), $scope)->groupBy('event_id');
        $events = TrainingEvent::query()->forCompany($this->tenantOf($reader), $companyId)
            ->orderByDesc('starts_at')->get();

        if ($events->isEmpty()) {
            return [];
        }

        $attended = $this->attendedCounts($this->tenantOf($reader), $companyId, $events->pluck('id')->all(), $scope);
        $names = $this->participantNames($this->tenantOf($reader), $companyId, $events->pluck('id')->all());
        $followups = $this->followupStates($this->tenantOf($reader), $companyId, $evaluations->flatten()->all());

        return $events->map(function (TrainingEvent $event) use ($reader, $evaluations, $attended, $names, $followups): array {
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
                'means' => array_map(static fn (array $criterion): ?float => $criterion['mean'], $means = $reader->means($rows)),
                'answered' => array_map(static fn (array $criterion): int => $criterion['answered_count'], $means),
                'paper_entries' => $rows->filter(static fn (TrainingEvaluation $evaluation): bool => $evaluation->enteredFromPaper())->count(),
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
    private function attendedCounts(int $tenantId, int $companyId, array $eventIds, ?array $scope = null): array
    {
        return TrainingParticipationFact::query()->forCompany($tenantId, $companyId)->current()
            ->whereIn('event_id', $eventIds)
            ->where('attendance', AttendanceStatus::Present->value)
            ->when($scope !== null, fn ($query) => $query->whereIn(
                'participant_id',
                TrainingParticipant::query()->forCompany($tenantId, $companyId)
                    ->whereIn('employee_subject_id', $scope)->select('id'),
            ))
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
