<?php

namespace App\Domains\People\Training\Livewire\Calendar;

use App\Base\Authz\Exceptions\AuthorizationDeniedException;
use App\Base\Tenancy\Contracts\TenantContext;
use App\Domains\People\Provider\Data\ExternalReference;
use App\Domains\People\Skills\Services\SkillAudience;
use App\Domains\People\Skills\Services\WorkforceSubjects;
use App\Domains\People\Training\Exceptions\InvalidTrainingParticipationException;
use App\Domains\People\Training\Models\TrainingEvent;
use App\Domains\People\Training\Models\TrainingParticipant;
use App\Domains\People\Training\Services\TrainingAudience;
use App\Domains\People\Training\Services\TrainingParticipationStore;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

final class Index extends Component
{
    use WithPagination;

    private const PER_PAGE = 15;

    public ?int $companyEntityId = null;

    public int $year = 0;

    public int $month = 0;

    /** Calendar is the landing view; the register is its alternate, not a third list. */
    #[Url]
    public string $view = 'calendar';

    #[Url]
    public string $search = '';

    #[Url]
    public string $lifecycle = '';

    #[Url]
    public string $department = '';

    #[Url]
    public string $from = '';

    #[Url]
    public string $until = '';

    #[Url]
    public string $sortBy = 'starts_at';

    #[Url]
    public string $sortDir = 'asc';

    /** @var array<int, string>|null */
    private ?array $companies = null;

    public function mount(TrainingAudience $audience): void
    {
        $companies = $this->allowedCompanies($audience);
        $this->companyEntityId = count($companies) > 0 ? (int) array_key_first($companies) : null;
        $now = now();
        $this->year = $now->year;
        $this->month = $now->month;
    }

    public function selectCompany(int $companyEntityId, TrainingAudience $audience): void
    {
        abort_unless(array_key_exists($companyEntityId, $this->allowedCompanies($audience)), 404);
        $this->companyEntityId = $companyEntityId;
        $this->resetPage();
    }

    public function previousMonth(): void
    {
        $date = CarbonImmutable::create($this->year, $this->month, 1)->subMonth();
        $this->year = $date->year;
        $this->month = $date->month;
    }

    public function nextMonth(): void
    {
        $date = CarbonImmutable::create($this->year, $this->month, 1)->addMonth();
        $this->year = $date->year;
        $this->month = $date->month;
    }

    public function showCalendar(): void
    {
        $this->view = 'calendar';
        $this->resetPage();
    }

    public function showTable(): void
    {
        $this->view = 'table';
        $this->resetPage();
    }

    public function sort(string $column): void
    {
        abort_unless(in_array($column, ['starts_at', 'course_title_snapshot', 'status'], true), 404);
        $this->sortDir = $this->sortBy === $column && $this->sortDir === 'asc' ? 'desc' : 'asc';
        $this->sortBy = $column;
        $this->resetPage();
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedLifecycle(): void
    {
        $this->resetPage();
    }

    public function updatedDepartment(): void
    {
        $this->resetPage();
    }

    public function updatedFrom(): void
    {
        $this->resetPage();
    }

    public function updatedUntil(): void
    {
        $this->resetPage();
    }

    public function enrol(int $eventId, TrainingParticipationStore $participation): void
    {
        abort_unless($this->companyEntityId !== null, 404);

        try {
            $participation->enrolSelf(Auth::user(), $this->companyEntityId, $eventId);
        } catch (InvalidTrainingParticipationException $exception) {
            $this->addError('enrolment', $exception->getMessage());
        }
    }

    public function withdraw(int $eventId, TrainingParticipationStore $participation): void
    {
        abort_unless($this->companyEntityId !== null, 404);

        try {
            $participation->withdrawSelf(Auth::user(), $this->companyEntityId, $eventId);
        } catch (InvalidTrainingParticipationException $exception) {
            $this->addError('enrolment', $exception->getMessage());
        }
    }

    public function render(TrainingAudience $audience): View
    {
        if (! in_array($this->view, ['calendar', 'table'], true)) {
            $this->view = 'calendar';
        }
        if (! in_array($this->sortBy, ['starts_at', 'course_title_snapshot', 'status'], true)) {
            $this->sortBy = 'starts_at';
        }
        if (! in_array($this->sortDir, ['asc', 'desc'], true)) {
            $this->sortDir = 'asc';
        }

        $companies = $this->allowedCompanies($audience);
        $company = $this->companyEntityId;
        $events = collect();
        $tableEvents = null;
        $enrolled = [];
        $counts = [];
        $departments = collect();
        $canManage = false;

        if ($company !== null && array_key_exists($company, $companies)) {
            $canManage = $audience->canManage(Auth::user(), $company);
            $query = $this->filteredEvents($audience, $company, $canManage);
            $events = $this->view === 'calendar'
                ? (clone $query)->orderBy('starts_at')->get()
                : collect();
            $tableEvents = $this->view === 'table'
                ? $query->orderBy($this->sortBy, $this->sortDir)->paginate(self::PER_PAGE)
                : null;
            $shown = $this->view === 'table' ? $tableEvents->getCollection() : $events;
            $tenant = app(TenantContext::class)->requireTenantId();
            $counts = TrainingParticipant::query()
                ->forCompany($tenant, $company)
                ->whereIn('event_id', $shown->pluck('id')->all())
                ->whereNull('withdrawn_at')
                ->selectRaw('event_id, count(*) as enrolled')
                ->groupBy('event_id')
                ->pluck('enrolled', 'event_id')
                ->map(intval(...))
                ->all();
            $enrolled = $this->enrolledEventIds($company);
            $departments = $canManage ? $this->departmentOptions($company) : collect();
        }

        $monthStart = CarbonImmutable::create($this->year, $this->month, 1)->startOfDay();

        return view('people::livewire.calendar.index', [
            'companies' => $companies,
            'events' => $events,
            'tableEvents' => $tableEvents,
            'enrolled' => $enrolled,
            'counts' => $counts,
            'departments' => $departments,
            'canManage' => $canManage,
            'weeks' => $this->weeks($monthStart, $events),
            'monthLabel' => $monthStart->format('F Y'),
        ]);
    }

    private function filteredEvents(TrainingAudience $audience, int $company, bool $canManage): Builder
    {
        // HR's Table is the schedule register, including completed and cancelled
        // records; employee discovery remains deliberately limited to the calendar seam.
        $query = $canManage
            ? $audience->visibleEvents(Auth::user(), $company)
            : $audience->visibleCalendarEvents(Auth::user(), $company);

        return $query
            ->when($this->search !== '', function (Builder $builder): void {
                $like = '%'.addcslashes(mb_strtolower($this->search), '%_\\').'%';
                // The company-scoped builder deliberately rejects orWhere;
                // keep the two search columns one AND-safe predicate instead.
                $builder->whereRaw('(lower(course_title_snapshot) like ? or lower(course_code_snapshot) like ?)', [$like, $like]);
            })
            ->when($this->lifecycle !== '', fn (Builder $builder) => $builder->where('status', $this->lifecycle))
            ->when($canManage && $this->department !== '', fn (Builder $builder) => $builder->where('target_department_entity_id', $this->department))
            ->when($this->from !== '', fn (Builder $builder) => $builder->whereDate('starts_at', '>=', $this->from))
            ->when($this->until !== '', fn (Builder $builder) => $builder->whereDate('starts_at', '<=', $this->until));
    }

    /** @return array<int, string> */
    private function allowedCompanies(TrainingAudience $audience): array
    {
        if ($this->companies !== null) {
            return $this->companies;
        }

        try {
            return $this->companies = $audience->allowedCalendarCompanies(Auth::user());
        } catch (AuthorizationDeniedException) {
            abort(403);
        }
    }

    /** @return list<int> */
    private function enrolledEventIds(int $company): array
    {
        $bound = app(SkillAudience::class)->boundEmployeeEntityId(Auth::user(), $company);
        if ($bound === null) {
            return [];
        }

        return TrainingParticipant::query()
            ->forCompany(app(TenantContext::class)->requireTenantId(), $company)
            ->where('provider_id', ExternalReference::PROVIDER_ID)
            ->where('employee_subject_id', (string) $bound)
            ->whereNull('withdrawn_at')
            ->pluck('event_id')->map(intval(...))->all();
    }

    /** @return Collection<int, object> */
    private function departmentOptions(int $companyEntityId): Collection
    {
        return collect(app(WorkforceSubjects::class)->organizationUnits($companyEntityId))
            ->map(static fn ($unit): object => (object) [
                'workforce_entity_id' => (int) $unit->reference->externalId,
                'name' => $unit->name,
            ])
            ->values();
    }

    /**
     * @param  Collection<int, TrainingEvent>  $events
     * @return list<list<array{date: CarbonImmutable, current: bool, events: list<TrainingEvent>}>>
     */
    private function weeks(CarbonImmutable $monthStart, $events): array
    {
        $cursor = $monthStart->startOfWeek(CarbonImmutable::MONDAY);
        $end = $monthStart->endOfMonth()->endOfWeek(CarbonImmutable::SUNDAY);
        $byDay = [];
        foreach ($events as $event) {
            $key = $event->starts_at->format('Y-m-d');
            $byDay[$key][] = $event;
        }

        $weeks = [];
        while ($cursor->lessThanOrEqualTo($end)) {
            $week = [];
            for ($day = 0; $day < 7; $day++) {
                $week[] = [
                    'date' => $cursor,
                    'current' => $cursor->month === $monthStart->month,
                    'events' => $byDay[$cursor->format('Y-m-d')] ?? [],
                ];
                $cursor = $cursor->addDay();
            }
            $weeks[] = $week;
        }

        return $weeks;
    }
}
