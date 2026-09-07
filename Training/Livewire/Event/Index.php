<?php

namespace App\Domains\People\Training\Livewire\Event;

use App\Base\Tenancy\Contracts\TenantContext;
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
use App\Domains\People\Training\Models\TrainingEventAuditEvent;
use App\Domains\People\Training\Models\TrainingParticipant;
use App\Domains\People\Training\Models\TrainingParticipationFact;
use App\Domains\People\Training\Services\TrainingAudience;
use App\Domains\People\Training\Services\TrainingEventStore;
use App\Domains\People\Training\Services\TrainingParticipationStore;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Url;
use Livewire\Component;

final class Index extends Component
{
    public ?int $companyEntityId = null;

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
        $this->companyEntityId = count($companies) > 0 ? (int) array_key_first($companies) : null;
        $this->startsAt = now()->addWeek()->startOfHour()->format('Y-m-d\TH:i');
        $this->endsAt = now()->addWeek()->addHours(2)->startOfHour()->format('Y-m-d\TH:i');
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
        $summaries = [];
        $canManage = false;

        if ($company !== null && array_key_exists($company, $companies)) {
            $statuses = array_values(array_filter(explode(',', $this->status)));
            $events = $audience->visibleEvents(Auth::user(), $company)
                ->when($statuses !== [], fn ($query) => $query->whereIn('status', $statuses))
                ->when($this->department !== '', fn ($query) => $query->where('target_department_entity_id', $this->department))
                ->orderByDesc('starts_at')->get();
            $canManage = $audience->canManage(Auth::user(), $company);
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
        }

        $facts = $company !== null && array_key_exists($company, $companies)
            ? $this->confirmedFactRows($company, $events->modelKeys(), $employees)
            : collect();

        return view('people::livewire.event.index', compact(
            'companies', 'events', 'courses', 'departments', 'employees', 'history', 'summaries', 'canManage', 'facts',
        ));
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
    }

    private function resetForm(): void
    {
        $this->reset('editingEventId', 'courseId', 'targetDepartmentEntityId', 'organizerEmployeeEntityId',
            'internalTrainerEmployeeEntityId', 'deliveryMode', 'externalTrainerReference', 'externalTrainerName', 'venue');
        $this->capacity = 1;
    }
}
