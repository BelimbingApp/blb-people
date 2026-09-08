<?php

namespace App\Domains\People\Training\Livewire\Event;

use App\Base\Foundation\Contracts\SemanticActionRecorder;
use App\Base\Tenancy\Contracts\TenantContext;
use App\Core\User\Models\User;
use App\Domains\People\Skills\Services\SkillAudience;
use App\Domains\People\Skills\Services\WorkforceSubjects;
use App\Domains\People\Training\Contracts\SummarizesTrainingParticipation;
use App\Domains\People\Training\Data\ParticipationFactDraft;
use App\Domains\People\Training\Data\TrainingEventDraft;
use App\Domains\People\Training\Enums\AttendanceStatus;
use App\Domains\People\Training\Enums\DeliveryMode;
use App\Domains\People\Training\Enums\TrainingEventStatus;
use App\Domains\People\Training\Exceptions\InvalidTrainingEventException;
use App\Domains\People\Training\Exceptions\InvalidTrainingParticipationException;
use App\Domains\People\Training\Models\TrainingCourse;
use App\Domains\People\Training\Models\TrainingEvent;
use App\Domains\People\Training\Models\TrainingEventAuditEvent;
use App\Domains\People\Training\Models\TrainingParticipant;
use App\Domains\People\Training\Models\TrainingParticipationFact;
use App\Domains\People\Training\Models\TrainingSession;
use App\Domains\People\Training\Services\TrainingAudience;
use App\Domains\People\Training\Services\TrainingEventStore;
use App\Domains\People\Training\Services\TrainingParticipationStore;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Url;
use Livewire\Component;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class Index extends Component
{
    /** One audit action per attendance register download (0011-f). */
    public const EXPORT_EVENT = 'people.training.participation.exported';

    /**
     * The `11 Training Attendance` sheet order, so the file round-trips into
     * the attendance-sheet import (0011-c).
     */
    public const EXPORT_COLUMNS = ['employee_subject_id', 'employee_name', 'session_reference', 'attendance', 'actual_minutes',
        'pre_test_score', 'post_test_score', 'improvement', 'pass_result', 'certificate_reference', 'certificate_valid_from',
        'certificate_valid_until', 'confirmed_at', 'source', 'corrected'];

    #[Url(as: 'company')]
    public ?int $companyEntityId = null;

    /** The Schedule area opens this editor with an explicit return path. */
    #[Url(as: 'edit')]
    public ?int $requestedEditId = null;

    #[Url(as: 'return')]
    public string $returnTo = '';

    #[Url(as: 'view')]
    public string $calendarView = 'calendar';

    #[Url(as: 'search')]
    public string $calendarSearch = '';

    #[Url(as: 'lifecycle')]
    public string $calendarLifecycle = '';

    #[Url(as: 'from')]
    public string $calendarFrom = '';

    #[Url(as: 'until')]
    public string $calendarUntil = '';

    #[Url(as: 'sortBy')]
    public string $calendarSortBy = 'starts_at';

    #[Url(as: 'sortDir')]
    public string $calendarSortDir = 'asc';

    #[Url(as: 'year')]
    public int $calendarYear = 0;

    #[Url(as: 'month')]
    public int $calendarMonth = 0;

    #[Url(as: 'page')]
    public int $calendarPage = 1;

    public ?int $editingEventId = null;

    /** The confirmed fact HR is correcting, if any. */
    public ?int $correctingFactId = null;

    public string $correctionReason = '';

    public string $correctionAttendance = '';

    public int $correctionMinutes = 0;

    public ?int $courseId = null;

    public ?int $targetDepartmentEntityId = null;

    public ?int $organizerEmployeeEntityId = null;

    public ?int $internalTrainerEmployeeEntityId = null;

    public string $deliveryMode = '';

    public string $externalTrainerReference = '';

    public string $externalTrainerName = '';

    public string $venue = '';

    public string $startsAt = '';

    public string $endsAt = '';

    public int $capacity = 1;

    /** @var array<int, string> */
    public array $evidence = [];

    /** @var array<int, string> */
    public array $reason = [];

    /** @var array<int, string> */
    public array $comment = [];

    /**
     * Drill-down filters from the HR skill KPI dashboard (#363): comma-separated
     * event statuses, and one target department id. Empty means all.
     */
    #[Url]
    public string $status = '';

    #[Url]
    public string $department = '';

    /** @var array<int, string>|null */
    private ?array $companies = null;

    public function mount(TrainingAudience $audience): void
    {
        $companies = $this->allowedCompanies($audience);
        if ($this->companyEntityId === null) {
            $this->companyEntityId = count($companies) > 0 ? (int) array_key_first($companies) : null;
        } else {
            abort_unless(array_key_exists($this->companyEntityId, $companies), 404);
        }
        $this->startsAt = now()->addWeek()->startOfHour()->format('Y-m-d\TH:i');
        $this->endsAt = now()->addWeek()->addHours(2)->startOfHour()->format('Y-m-d\TH:i');

        if ($this->requestedEditId !== null) {
            $this->editEvent($this->requestedEditId, $audience);
        }
    }

    public function selectCompany(int $companyEntityId, TrainingAudience $audience): void
    {
        abort_unless(array_key_exists($companyEntityId, $this->allowedCompanies($audience)), 404);
        $this->companyEntityId = $companyEntityId;
        $this->resetForm();
    }

    public function editEvent(int $eventId, TrainingAudience $audience): void
    {
        $company = $this->managedCompany($audience);
        $event = $audience->visibleEvents(Auth::user(), $company)->whereKey($eventId)->firstOrFail();
        abort_unless($event->status === TrainingEventStatus::Scheduled, 409);
        $this->editingEventId = (int) $event->id;
        $this->courseId = (int) $event->course_id;
        $this->targetDepartmentEntityId = $event->target_department_entity_id === null ? null : (int) $event->target_department_entity_id;
        $this->organizerEmployeeEntityId = (int) $event->organizer_employee_entity_id;
        $this->internalTrainerEmployeeEntityId = $event->internal_trainer_employee_entity_id === null ? null : (int) $event->internal_trainer_employee_entity_id;
        $this->deliveryMode = $event->delivery_mode_snapshot->value;
        $this->externalTrainerReference = (string) $event->external_trainer_reference;
        $this->externalTrainerName = (string) $event->external_trainer_name_snapshot;
        $this->venue = (string) $event->venue;
        $this->startsAt = $event->starts_at->format('Y-m-d\TH:i');
        $this->endsAt = $event->ends_at->format('Y-m-d\TH:i');
        $this->capacity = (int) $event->capacity;
    }

    public function save(TrainingAudience $audience, TrainingEventStore $store): void
    {
        $company = $this->managedCompany($audience);
        $validated = $this->validate([
            'courseId' => ['required', 'integer'],
            'targetDepartmentEntityId' => ['nullable', 'integer'],
            'organizerEmployeeEntityId' => ['required', 'integer'],
            'internalTrainerEmployeeEntityId' => ['nullable', 'integer'],
            'deliveryMode' => ['nullable', Rule::enum(DeliveryMode::class)],
            'externalTrainerReference' => ['nullable', 'string', 'max:160'],
            'externalTrainerName' => ['nullable', 'string', 'max:255'],
            'venue' => ['nullable', 'string', 'max:255'],
            'startsAt' => ['required', 'date_format:Y-m-d\TH:i'],
            'endsAt' => ['required', 'date_format:Y-m-d\TH:i'],
            'capacity' => ['required', 'integer', 'min:1', 'max:1000000'],
        ]);
        $draft = new TrainingEventDraft(
            courseId: (int) $validated['courseId'],
            startsAt: new \DateTimeImmutable($validated['startsAt']),
            endsAt: new \DateTimeImmutable($validated['endsAt']),
            capacity: (int) $validated['capacity'],
            organizerEmployeeEntityId: (int) $validated['organizerEmployeeEntityId'],
            targetDepartmentEntityId: $this->targetDepartmentEntityId,
            deliveryMode: $this->deliveryMode === '' ? null : DeliveryMode::from($this->deliveryMode),
            venue: $this->venue,
            internalTrainerEmployeeEntityId: $this->internalTrainerEmployeeEntityId,
            externalTrainerReference: $this->externalTrainerReference,
            externalTrainerName: $this->externalTrainerName,
        );

        try {
            if ($this->editingEventId === null) {
                $store->schedule($company, $draft, (int) Auth::id(), $this->actorEmployeeId());
            } else {
                $store->revise($company, $this->editingEventId, $draft, (int) Auth::id(), $this->actorEmployeeId());
            }
        } catch (InvalidTrainingEventException $exception) {
            $this->addError('event', $exception->getMessage());

            return;
        }

        $this->resetForm();
        session()->flash('status', __('Training event saved.'));

        if ($this->returnTo === 'calendar') {
            $this->redirectRoute('people.training.calendar', $this->calendarReturnParameters());
        }
    }

    public function start(int $eventId, TrainingAudience $audience, TrainingEventStore $store): void
    {
        try {
            $store->start($this->managedCompany($audience), $eventId, (int) Auth::id(), $this->actorEmployeeId());
        } catch (InvalidTrainingEventException $exception) {
            $this->addError('event', $exception->getMessage());
        }
    }

    public function complete(int $eventId, TrainingAudience $audience, TrainingEventStore $store): void
    {
        try {
            $store->complete($this->managedCompany($audience), $eventId, (string) ($this->evidence[$eventId] ?? ''),
                (int) Auth::id(), $this->actorEmployeeId());
            unset($this->evidence[$eventId]);
        } catch (InvalidTrainingEventException $exception) {
            $this->addError('event', $exception->getMessage());
        }
    }

    public function cancel(int $eventId, TrainingAudience $audience, TrainingEventStore $store): void
    {
        try {
            $store->cancel($this->managedCompany($audience), $eventId, (string) ($this->reason[$eventId] ?? ''),
                (int) Auth::id(), $this->actorEmployeeId());
            unset($this->reason[$eventId]);
        } catch (InvalidTrainingEventException $exception) {
            $this->addError('event', $exception->getMessage());
        }
    }

    public function addComment(int $eventId, TrainingAudience $audience, TrainingEventStore $store): void
    {
        try {
            $store->comment($this->managedCompany($audience), $eventId, (string) ($this->comment[$eventId] ?? ''),
                actorUserId: (int) Auth::id(), actorEmployeeEntityId: $this->actorEmployeeId());
            unset($this->comment[$eventId]);
        } catch (InvalidTrainingEventException $exception) {
            $this->addError('event', $exception->getMessage());
        }
    }

    public function render(TrainingAudience $audience, SummarizesTrainingParticipation $participation): View
    {
        $companies = $this->allowedCompanies($audience);
        $company = $this->companyEntityId;
        $events = collect();
        $courses = collect();
        $departments = collect();
        $employees = collect();
        $history = collect();
        $historyRows = collect();
        $summaries = [];
        $canManage = false;
        $canExport = false;

        if ($company !== null && array_key_exists($company, $companies)) {
            $statuses = array_values(array_filter(explode(',', $this->status)));
            $events = $audience->visibleEvents(Auth::user(), $company)
                ->when($statuses !== [], fn ($query) => $query->whereIn('status', $statuses))
                ->when($this->department !== '', fn ($query) => $query->where('target_department_entity_id', $this->department))
                ->orderByDesc('starts_at')->get();
            $canManage = $audience->canManage(Auth::user(), $company);
            $canExport = $audience->canExport(Auth::user(), $company);
            $tenant = app(TenantContext::class)->requireTenantId();
            $departments = $this->departmentOptions($company);
            $employees = $this->employeeOptions($company);

            if ($canManage) {
                $courses = TrainingCourse::query()->forCompany($tenant, $company)->where('active', true)->orderBy('title')->get();
            } else {
                // A viewer sees only the departments and people the visible
                // events actually name, exactly as the projection query did.
                $departments = $departments->whereIn(
                    'workforce_entity_id',
                    $events->pluck('target_department_entity_id')->filter()->unique(),
                )->values();
                $employees = $employees->whereIn(
                    'workforce_entity_id',
                    $events->pluck('organizer_employee_entity_id')
                        ->merge($events->pluck('internal_trainer_employee_entity_id'))->filter()->unique(),
                )->values();
            }
            $history = TrainingEventAuditEvent::query()
                ->forCompany(app(TenantContext::class)->requireTenantId(), $company)
                ->whereIn('training_event_id', $events->pluck('id'))
                ->orderByDesc('occurred_at')->get()->groupBy('training_event_id');
            $summaries = $participation->forEvents($company, $events->pluck('id')->map(intval(...))->all());

            // History actors may not appear on the event itself (notes, system
            // lifecycle). Widen the employee map and resolve user display names
            // so the disclosure can name who acted without a second query in Blade.
            $historyRows = $history->flatten(1);
            $employees = $employees->merge(
                $this->employeeOptions($company)->whereIn(
                    'workforce_entity_id',
                    $historyRows->pluck('actor_employee_entity_id')->filter()->unique(),
                ),
            )->unique('workforce_entity_id')->values();
        }

        $historyActors = $historyRows->isEmpty()
            ? collect()
            : User::query()
                ->whereIn('id', $historyRows->pluck('actor_user_id')->filter()->unique()->all())
                ->get()
                ->keyBy('id');

        $facts = $company !== null && array_key_exists($company, $companies)
            ? $this->confirmedFactRows($company, $events->modelKeys(), $employees)
            : collect();

        return view('people::livewire.event.index', compact(
            'companies', 'events', 'courses', 'departments', 'employees', 'history', 'historyActors', 'summaries', 'canManage', 'canExport', 'facts',
        ));
    }

    /**
     * The event's attendance register as CSV: its current participation
     * facts, one row per participant and session, in the `11 Training
     * Attendance` column order; one audit action per download (0011-f).
     *
     * The event is read under the selected company's scope, so an id from a
     * sibling company or another tenant is a 404 rather than a refusal that
     * confirms the row exists. Nothing is written except the audit action.
     */
    public function exportAttendance(int $eventId, TrainingAudience $audience): StreamedResponse
    {
        $companyId = $this->companyEntityId;
        abort_unless($companyId !== null, 404);
        $audience->authorizeExport(Auth::user(), $companyId);

        $event = TrainingEvent::query()
            ->forCompany(app(TenantContext::class)->requireTenantId(), $companyId)
            ->whereKey($eventId)
            ->firstOrFail();
        $rows = $this->attendanceRows($companyId, $event);
        $factIds = $rows->pluck('fact_id')->filter()->map(intval(...))->values()->all();
        $filename = sprintf('training-attendance-%d-%d.csv', $companyId, (int) $event->id);

        app(SemanticActionRecorder::class)->record(
            event: self::EXPORT_EVENT,
            summary: __('Exported :count attendance rows of training event :event to CSV', ['count' => $rows->count(), 'event' => $event->id]),
            source: __('Training'),
            subject: ['name' => 'training-attendance', 'identifier' => $filename],
            surface: 'people.training.events.index',
            uiElement: 'export-attendance',
            context: [
                'company_entity_id' => $companyId,
                'training_event_id' => (int) $event->id,
                'rows' => $rows->count(),
                'fact_ids' => $factIds,
            ],
        );

        return response()->streamDownload(function () use ($rows): void {
            $out = fopen('php://output', 'wb');
            fputcsv($out, self::EXPORT_COLUMNS);
            foreach ($rows as $row) {
                fputcsv($out, array_map(static fn (string $column): string => (string) $row[$column], self::EXPORT_COLUMNS));
            }
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv']);
    }

    /**
     * What the event's register currently says, participant by participant.
     *
     * current() is the same answer the participation summary and the facts
     * table give: a corrected fact is represented by its correction, never by
     * both rows. A participant with no current fact is still a row: withdrawn
     * ones as `cancelled`, the rest as `nominated`, with the fact columns
     * empty. Dates come from the model casts so the file never carries the
     * time part a raw date column may store.
     *
     * @return Collection<int, array<string, int|string|null>>
     */
    private function attendanceRows(int $companyId, TrainingEvent $event): Collection
    {
        $tenant = app(TenantContext::class)->requireTenantId();
        $names = $this->employeeOptions($companyId)->pluck('display_name', 'workforce_entity_id');
        $participants = TrainingParticipant::query()->forCompany($tenant, $companyId)
            ->where('event_id', (int) $event->id)->orderBy('id')->get();
        $sessions = TrainingSession::query()->forCompany($tenant, $companyId)
            ->where('event_id', (int) $event->id)->get()->keyBy('id');
        $facts = $participants->isEmpty() ? collect() : TrainingParticipationFact::query()->forCompany($tenant, $companyId)
            ->current()
            ->where('event_id', (int) $event->id)
            ->whereIn('participant_id', $participants->modelKeys())
            ->orderBy('session_id')->orderBy('id')
            ->get()->groupBy('participant_id');

        $rows = collect();
        foreach ($participants as $participant) {
            $subjectId = (string) $participant->employee_subject_id;
            $base = [
                'fact_id' => null,
                'employee_subject_id' => $subjectId,
                'employee_name' => (string) ($names[(int) $subjectId] ?? __('Unknown participant')),
                'session_reference' => '', 'attendance' => '', 'actual_minutes' => '',
                'pre_test_score' => '', 'post_test_score' => '', 'improvement' => '', 'pass_result' => '',
                'certificate_reference' => '', 'certificate_valid_from' => '', 'certificate_valid_until' => '',
                'confirmed_at' => '', 'source' => '', 'corrected' => '',
            ];
            $own = $facts->get($participant->id, collect());
            if ($own->isEmpty()) {
                $rows->push(['attendance' => $participant->withdrawn_at === null ? 'nominated' : AttendanceStatus::Cancelled->value] + $base);

                continue;
            }
            foreach ($own as $fact) {
                $pre = self::testScore($fact->pre_test);
                $post = self::testScore($fact->post_test);
                $rows->push([
                    'fact_id' => (int) $fact->id,
                    'session_reference' => (string) ($sessions->get($fact->session_id)?->session_reference ?? ''),
                    'attendance' => $fact->attendance->value,
                    'actual_minutes' => (int) $fact->actual_minutes,
                    'pre_test_score' => $pre === null ? '' : (string) $pre,
                    'post_test_score' => $post === null ? '' : (string) $post,
                    'improvement' => $pre === null || $post === null ? '' : (string) ($post - $pre),
                    'pass_result' => match ($fact->post_test['passed'] ?? null) {
                        true => 'pass', false => 'fail', default => ''
                    },
                    'certificate_reference' => (string) ($fact->certificate_reference ?? ''),
                    'certificate_valid_from' => $fact->certificate_valid_from?->format('Y-m-d') ?? '',
                    'certificate_valid_until' => $fact->certificate_valid_until?->format('Y-m-d') ?? '',
                    'confirmed_at' => $fact->confirmed_at?->format('Y-m-d H:i:s') ?? '',
                    'source' => (string) $fact->source,
                    'corrected' => (int) $fact->supersedes_fact_id > 0 ? 'yes' : 'no',
                ] + $base);
            }
        }

        return $rows;
    }

    /** The recorded score of an applicable test, or null when absent or not applicable. */
    private static function testScore(mixed $result): ?float
    {
        if (! is_array($result) || ($result['applicable'] ?? false) !== true || ! isset($result['score'])) {
            return null;
        }

        return (float) $result['score'];
    }

    /**
     * Confirmed participation for the visible events, as it currently stands.
     *
     * current() means a corrected fact is represented by its correction, not
     * by both rows: the table answers what happened, and the reason column
     * says when that answer replaced an earlier one.
     *
     * @param  list<int>  $eventIds
     * @param  Collection<int, object>  $employees
     * @return Collection<int, object>
     */
    private function confirmedFactRows(int $companyEntityId, array $eventIds, Collection $employees): Collection
    {
        if ($eventIds === []) {
            return collect();
        }

        $tenant = app(TenantContext::class)->requireTenantId();
        $names = $employees->pluck('display_name', 'workforce_entity_id');
        $participants = TrainingParticipant::query()->forCompany($tenant, $companyEntityId)
            ->whereIn('event_id', $eventIds)->get()->keyBy('id');

        return TrainingParticipationFact::query()->forCompany($tenant, $companyEntityId)
            ->current()
            ->whereIn('event_id', $eventIds)
            ->whereNotNull('confirmed_at')
            ->orderByDesc('id')
            ->get()
            ->map(static function (TrainingParticipationFact $fact) use ($participants, $names): object {
                $participant = $participants->get($fact->participant_id);
                $subjectId = $participant === null ? null : (int) $participant->employee_subject_id;

                return (object) [
                    'id' => (int) $fact->id,
                    'event_id' => (int) $fact->event_id,
                    'participant' => (string) ($names[$subjectId] ?? __('Unknown participant')),
                    'attendance' => $fact->attendance,
                    'actual_minutes' => (int) $fact->actual_minutes,
                    'corrected' => (int) $fact->supersedes_fact_id > 0,
                    'reason' => (string) ($fact->correction_reason ?? ''),
                ];
            });
    }

    /**
     * Open the correction form for one confirmed fact.
     *
     * Nothing is written here; the store decides whether this actor may
     * correct anything, and it decides again on save.
     */
    public function startCorrection(int $factId): void
    {
        $fact = $this->confirmedFact($factId);
        $this->correctingFactId = (int) $fact->id;
        $this->correctionAttendance = $fact->attendance->value;
        $this->correctionMinutes = (int) $fact->actual_minutes;
        $this->correctionReason = '';
    }

    public function cancelCorrection(): void
    {
        $this->correctingFactId = null;
        $this->correctionReason = '';
        $this->correctionAttendance = '';
        $this->correctionMinutes = 0;
    }

    /** Append the superseding fact through the store, which owns every rule. */
    public function saveCorrection(TrainingParticipationStore $store): void
    {
        $companyId = $this->companyEntityId;
        $factId = $this->correctingFactId;
        abort_unless($companyId !== null && $factId !== null, 404);

        $this->validate([
            'correctionReason' => ['required', 'string', 'min:3', 'max:2000'],
            'correctionAttendance' => ['required', Rule::enum(AttendanceStatus::class)],
            'correctionMinutes' => ['required', 'integer', 'min:0'],
        ]);

        $fact = $this->confirmedFact($factId);

        try {
            $store->correct(Auth::user(), $companyId, (int) $fact->id, new ParticipationFactDraft(
                attendance: AttendanceStatus::from($this->correctionAttendance),
                actualMinutes: $this->correctionMinutes,
                source: 'correction',
                sourceReference: 'fact:'.$fact->id,
                preTest: null,
                postTest: null,
                certificateReference: $fact->certificate_reference,
                certificateValidFrom: $fact->certificate_valid_from,
                certificateValidUntil: $fact->certificate_valid_until,
                evidenceReferences: $fact->evidence_references ?? [],
            ), $this->correctionReason);
        } catch (InvalidTrainingParticipationException $refusal) {
            $this->addError('correctionReason', $refusal->getMessage());

            return;
        }

        $this->cancelCorrection();
    }

    /**
     * The fact being corrected, read under the company scope so a fact id
     * from another company or tenant is a 404 rather than a refusal that
     * confirms the row exists.
     */
    private function confirmedFact(int $factId): TrainingParticipationFact
    {
        $companyId = $this->companyEntityId;
        abort_unless($companyId !== null, 404);

        $fact = TrainingParticipationFact::query()
            ->forCompany(app(TenantContext::class)->requireTenantId(), $companyId)
            ->whereNotNull('confirmed_at')
            ->find($factId);

        abort_if($fact === null, 404);

        return $fact;
    }

    /** @return array<int, string> */
    private function allowedCompanies(TrainingAudience $audience): array
    {
        return $this->companies ??= $audience->allowedCompanies(Auth::user());
    }

    /**
     * The seam publishes typed records; these views have always read
     * `workforce_entity_id` with `name` or `display_name`. Mapping to that
     * shape keeps the relocation invisible to the Blade, which is not what
     * this lane is changing.
     *
     * @return Collection<int, object>
     */
    private function departmentOptions(int $companyEntityId): Collection
    {
        return collect(app(WorkforceSubjects::class)->organizationUnits($companyEntityId))
            ->map(static fn ($unit): object => (object) [
                'workforce_entity_id' => (int) $unit->reference->externalId,
                'name' => $unit->name,
            ])
            ->values();
    }

    /** @return Collection<int, object> */
    private function employeeOptions(int $companyEntityId): Collection
    {
        return collect(app(WorkforceSubjects::class)->employees($companyEntityId))
            ->map(static fn ($employee): object => (object) [
                'workforce_entity_id' => (int) $employee->reference->externalId,
                'display_name' => $employee->displayName,
            ])
            ->sortBy('display_name')
            ->values();
    }

    private function managedCompany(TrainingAudience $audience): int
    {
        abort_unless($this->companyEntityId !== null, 404);
        $audience->authorizeManage(Auth::user(), $this->companyEntityId);

        return $this->companyEntityId;
    }

    private function actorEmployeeId(): ?int
    {
        return $this->companyEntityId === null ? null
            : app(SkillAudience::class)->boundEmployeeEntityId(Auth::user(), $this->companyEntityId);
    }

    public function cancelEdit(): void
    {
        $this->resetForm();

        if ($this->returnTo === 'calendar') {
            $this->redirectRoute('people.training.calendar', $this->calendarReturnParameters());
        }
    }

    /** @return array<string, int|string|null> */
    private function calendarReturnParameters(): array
    {
        return [
            'company' => $this->companyEntityId,
            'view' => $this->calendarView,
            'search' => $this->calendarSearch,
            'lifecycle' => $this->calendarLifecycle,
            'department' => $this->department,
            'from' => $this->calendarFrom,
            'until' => $this->calendarUntil,
            'sortBy' => $this->calendarSortBy,
            'sortDir' => $this->calendarSortDir,
            'year' => $this->calendarYear,
            'month' => $this->calendarMonth,
            'page' => $this->calendarPage,
        ];
    }

    private function resetForm(): void
    {
        $this->reset('editingEventId', 'courseId', 'targetDepartmentEntityId', 'organizerEmployeeEntityId',
            'internalTrainerEmployeeEntityId', 'deliveryMode', 'externalTrainerReference', 'externalTrainerName', 'venue');
        $this->capacity = 1;
    }
}
