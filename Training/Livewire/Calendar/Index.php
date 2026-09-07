<?php

namespace App\Domains\People\Training\Livewire\Calendar;

use App\Base\Authz\Exceptions\AuthorizationDeniedException;
use App\Base\Tenancy\Contracts\TenantContext;
use App\Domains\People\Provider\Data\ExternalReference;
use App\Domains\People\Skills\Services\SkillAudience;
use App\Domains\People\Training\Exceptions\InvalidTrainingParticipationException;
use App\Domains\People\Training\Models\TrainingEvent;
use App\Domains\People\Training\Models\TrainingParticipant;
use App\Domains\People\Training\Services\TrainingAudience;
use App\Domains\People\Training\Services\TrainingParticipationStore;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

final class Index extends Component
{
    public ?int $companyEntityId = null;

    public int $year = 0;

    public int $month = 0;

    public string $mode = 'month';

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

    public function showMonth(): void
    {
        $this->mode = 'month';
    }

    public function showList(): void
    {
        $this->mode = 'list';
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
        $companies = $this->allowedCompanies($audience);
        $company = $this->companyEntityId;
        $events = collect();
        $enrolled = [];
        $counts = [];

        if ($company !== null && array_key_exists($company, $companies)) {
            $events = $audience->visibleCalendarEvents(Auth::user(), $company)
                ->orderBy('starts_at')->get();
            $tenant = app(TenantContext::class)->requireTenantId();
            $counts = TrainingParticipant::query()
                ->forCompany($tenant, $company)
                ->whereIn('event_id', $events->pluck('id')->all())
                ->whereNull('withdrawn_at')
                ->selectRaw('event_id, count(*) as enrolled')
                ->groupBy('event_id')
                ->pluck('enrolled', 'event_id')
                ->map(intval(...))
                ->all();
            $enrolled = $this->enrolledEventIds($company);
        }

        $monthStart = CarbonImmutable::create($this->year, $this->month, 1)->startOfDay();

        return view('people::livewire.calendar.index', [
            'companies' => $companies,
            'events' => $events,
            'enrolled' => $enrolled,
            'counts' => $counts,
            'weeks' => $this->weeks($monthStart, $events),
            'monthLabel' => $monthStart->format('F Y'),
        ]);
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
