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

    #[Url(as: 'company')]
    public ?int $companyEntityId = null;

    #[Url]
    public int $year = 0;

    #[Url]
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
        if ($this->companyEntityId === null) {
            $this->companyEntityId = count($companies) > 0 ? (int) array_key_first($companies) : null;
        } else {
            abort_unless(array_key_exists($this->companyEntityId, $companies), 404);
        }
        $now = now();
        $this->year = $this->year === 0 ? $now->year : $this->year;
        $this->month = $this->month === 0 ? $now->month : $this->month;
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
        $this->syncTableDateRange();
    }

    public function nextMonth(): void
    {
        $date = CarbonImmutable::create($this->year, $this->month, 1)->addMonth();
        $this->year = $date->year;
        $this->month = $date->month;
        $this->syncTableDateRange();
    }

    public function selectMonth(int $year, int $month): void
    {
        abort_unless($year >= 2000 && $year <= 2100 && $month >= 1 && $month <= 12, 404);
        $this->year = $year;
        $this->month = $month;
        $this->syncTableDateRange();
    }

    public function updatedYear(): void
    {
        $this->selectMonth($this->year, $this->month);
    }

    public function updatedMonth(): void
    {
        $this->selectMonth($this->year, $this->month);
    }

    public function showCalendar(): void
    {
        $this->view = 'calendar';
        // Table filters are intentionally table-only: keeping them silently
        // active on the filter-free month view makes discovery deceptive.
        $this->reset('search', 'lifecycle', 'department', 'from', 'until');
        $this->sortBy = 'starts_at';
        $this->sortDir = 'asc';
        $this->resetPage();
    }

    public function showTable(): void
    {
        $this->view = 'table';
        $this->syncTableDateRange();
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
        $canSelfManageParticipation = false;

        if ($company !== null && array_key_exists($company, $companies)) {
            $canManage = $audience->canManage(Auth::user(), $company);
            $query = $this->filteredEvents($audience, $company, $canManage, $this->view === 'calendar');
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
            $canSelfManageParticipation = $this->canSelfManageParticipation($company);
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
            'canSelfManageParticipation' => $canSelfManageParticipation,
            'weeks' => $this->weeks($monthStart, $events),
            'monthLabel' => $monthStart->format('F Y'),
            'yearOptions' => range(now()->year - 10, now()->year + 20),
        ]);
    }

    /** @return array<string, int|string> */
    public function scheduleEditorParameters(?int $eventId = null): array
    {
        $parameters = [
            'return' => 'calendar',
            'company' => $this->companyEntityId,
            'view' => $this->view,
            'search' => $this->search,
            'lifecycle' => $this->lifecycle,
            'department' => $this->department,
            'from' => $this->from,
            'until' => $this->until,
            'sortBy' => $this->sortBy,
            'sortDir' => $this->sortDir,
            'year' => $this->year,
            'month' => $this->month,
            'page' => $this->getPage(),
        ];

        if ($eventId !== null) {
            $parameters['edit'] = $eventId;
        }

        return $parameters;
    }

    private function filteredEvents(TrainingAudience $audience, int $company, bool $canManage, bool $forCalendar): Builder
    {
        // The month calendar is discovery, not the HR register: it remains on
        // the open-event seam even for HR. Table is where terminal records live.
        $query = $forCalendar
            ? $audience->visibleCalendarEvents(Auth::user(), $company)
            : ($canManage
            ? $audience->visibleEvents(Auth::user(), $company)
            : $audience->visibleCalendarEvents(Auth::user(), $company));

        if ($forCalendar) {
            $monthStart = CarbonImmutable::create($this->year, $this->month, 1)->startOfMonth();

            return $query->whereBetween('starts_at', [$monthStart, $monthStart->endOfMonth()]);
        }

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

    private function syncTableDateRange(): void
    {
        if ($this->view !== 'table') {
            return;
        }

        $month = CarbonImmutable::create($this->year, $this->month, 1);
        $this->from = $month->startOfMonth()->toDateString();
        $this->until = $month->endOfMonth()->toDateString();
        $this->resetPage();
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

    private function canSelfManageParticipation(int $company): bool
    {
        return app(SkillAudience::class)->boundEmployeeEntityId(Auth::user(), $company) !== null;
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
