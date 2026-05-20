<?php

namespace App\Modules\People\Attendance\Livewire\Concerns;

use App\Modules\Core\Employee\Models\Employee;
use App\Modules\People\Attendance\Models\AttendanceDay;
use App\Modules\People\Attendance\Models\AttendanceRosterAssignment;
use App\Modules\People\Attendance\Models\AttendanceShiftTemplate;
use App\Modules\People\Attendance\Services\AttendanceCalendarResolver;
use App\Modules\People\Attendance\Support\DayTypeVocabulary;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

trait BuildsRosterGrid
{
    private function listWeekStart(): CarbonImmutable
    {
        if ($this->listWeekAnchor !== '') {
            try {
                return CarbonImmutable::parse($this->listWeekAnchor)->startOfWeek(CarbonImmutable::MONDAY);
            } catch (\Throwable) {
                // fall through to today's week
            }
        }

        return CarbonImmutable::today()->startOfWeek(CarbonImmutable::MONDAY);
    }

    /**
     * Start of the currently-browsed period, regardless of zoom level. Month
     * scope returns the first day of the month containing the anchor; week
     * scope returns the Monday of the anchor's week.
     */
    private function listScopeStart(): CarbonImmutable
    {
        $anchor = $this->listWeekAnchor !== ''
            ? (function (): CarbonImmutable {
                try {
                    return CarbonImmutable::parse($this->listWeekAnchor);
                } catch (\Throwable) {
                    return CarbonImmutable::today();
                }
            })()
            : CarbonImmutable::today();

        if ($this->listScope === 'month') {
            return $anchor->startOfMonth();
        }

        return $anchor->startOfWeek(CarbonImmutable::MONDAY);
    }

    private function listScopeEnd(): CarbonImmutable
    {
        $start = $this->listScopeStart();

        return $this->listScope === 'month'
            ? $start->endOfMonth()
            : $start->addDays(6);
    }

    private function gridPeriodStart(): CarbonImmutable
    {
        return $this->mode === 'list'
            ? $this->listScopeStart()
            : $this->safeGridStartDate();
    }

    private function gridPeriodEnd(): CarbonImmutable
    {
        return $this->mode === 'list'
            ? $this->listScopeEnd()
            : $this->safeGridEndDate($this->safeGridStartDate());
    }

    /**
     * @return list<array{date: string, day: string, label: string}>
     */
    private function rosterGridDays(): array
    {
        $start = $this->gridPeriodStart();
        $end = $this->gridPeriodEnd();
        $days = [];

        for ($date = $start; $date->lessThanOrEqualTo($end); $date = $date->addDay()) {
            $days[] = [
                'date' => $date->toDateString(),
                'day' => $date->format('D'),
                'label' => $date->format('M j'),
            ];
        }

        return $days;
    }

    /**
     * @param  Collection<int, Employee>  $employees
     * @return Collection<int, array<string, mixed>>
     */
    private function rosterGridRows(Collection $employees): Collection
    {
        if ($employees->isEmpty()) {
            return collect();
        }

        $days = $this->rosterGridDays();
        $start = $days[0]['date'] ?? now()->toDateString();
        $end = $days[array_key_last($days)]['date'] ?? $start;
        $employeeIds = $employees->pluck('id')->map(fn (int $id): int => $id)->all();
        $selectedIds = $this->selectedRosterEmployeeIds();
        $selectedLookup = array_fill_keys($selectedIds, true);
        $proposedShift = $this->selectedShiftTemplateCode();

        $assignments = AttendanceRosterAssignment::query()
            ->where('company_id', $this->companyId())
            ->whereIn('employee_id', $employeeIds)
            ->whereDate('effective_from', '<=', $end)
            ->where(function ($query) use ($start): void {
                $query->whereNull('effective_to')
                    ->orWhereDate('effective_to', '>=', $start);
            })
            ->with(['shiftTemplate', 'policyGroup', 'rosterPattern'])
            ->orderBy('effective_from')
            ->get()
            ->groupBy('employee_id');

        $calendar = app(AttendanceCalendarResolver::class);
        $calendar->preload($employees, $start, $end);

        return $employees->map(function (Employee $employee) use ($assignments, $days, $selectedLookup, $proposedShift, $calendar): array {
            $employeeAssignments = $assignments->get($employee->id, collect());

            return [
                'employee' => $employee,
                'group' => $this->employeeGroupLabel($employee),
                'cells' => collect($days)->mapWithKeys(
                    fn (array $day): array => [
                        $day['date'] => $this->rosterGridCell($employee, $day, $employeeAssignments, $selectedLookup, $proposedShift, $calendar),
                    ],
                )->all(),
            ];
        })->sortBy('group')->values();
    }

    private function employeeGroupLabel(Employee $employee): string
    {
        return $employee->department?->name
            ?? $employee->workProfile?->organizationUnit?->name
            ?? $employee->workProfile?->workforceClass?->name
            ?? '-';
    }

    /**
     * @param  array{date: string, day: string, label: string}  $day
     * @param  Collection<int, AttendanceRosterAssignment>  $employeeAssignments
     * @param  array<int, true>  $selectedLookup
     * @return array<string, mixed>
     */
    private function rosterGridCell(
        Employee $employee,
        array $day,
        Collection $employeeAssignments,
        array $selectedLookup,
        ?string $proposedShift,
        AttendanceCalendarResolver $calendar,
    ): array {
        $dayType = $calendar->dayType($employee, $day['date']);
        $dayTypeLabel = $this->dayTypeLabel($dayType);
        $assignment = $this->assignmentForGridDate($employeeAssignments, $day['date']);

        if ($assignment instanceof AttendanceRosterAssignment) {
            return $this->buildAssignedCell($assignment, $day, $dayType, $dayTypeLabel);
        }

        if (isset($selectedLookup[$employee->id]) && $proposedShift !== null && $this->dateWithinDraftRange($day['date'])) {
            return $this->buildPreviewCell($proposedShift, $dayType, $dayTypeLabel);
        }

        return $this->buildEmptyCell($dayType, $dayTypeLabel);
    }

    /**
     * @param  array{date: string, day: string, label: string}  $day
     * @return array<string, mixed>
     */
    private function buildAssignedCell(
        AttendanceRosterAssignment $assignment,
        array $day,
        string $dayType,
        string $dayTypeLabel,
    ): array {
        $title = __(':state assignment, policy :policy', [
            'state' => $this->statusLabel($assignment->publish_state),
            'policy' => $assignment->policyGroup?->code ?? '-',
        ]);
        if ($dayType !== AttendanceDay::DAY_TYPE_NORMAL) {
            $title .= ' · '.__('on :day', ['day' => $dayTypeLabel]);
        }

        return [
            'label' => $this->shiftCodeForGrid($assignment, $day['date']),
            'state' => $assignment->publish_state,
            'variant' => $assignment->publish_state === 'published' ? 'success' : 'warning',
            'policy' => $assignment->policyGroup?->code ?? '-',
            'title' => $title,
            'day_type' => $dayType,
            'day_type_label' => $dayTypeLabel,
            'on_non_working_day' => $dayType !== AttendanceDay::DAY_TYPE_NORMAL,
            'shift_template_id' => (int) ($assignment->attendance_shift_template_id ?? 0),
            'policy_group_id' => (int) ($assignment->attendance_policy_group_id ?? 0),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildPreviewCell(string $proposedShift, string $dayType, string $dayTypeLabel): array
    {
        $title = __('Unsaved roster preview');
        if ($dayType !== AttendanceDay::DAY_TYPE_NORMAL) {
            $title .= ' · '.__('on :day', ['day' => $dayTypeLabel]);
        }

        return [
            'label' => $proposedShift,
            'state' => 'preview',
            'variant' => 'info',
            'policy' => $this->selectedPolicyGroupCode() ?? '-',
            'title' => $title,
            'day_type' => $dayType,
            'day_type_label' => $dayTypeLabel,
            'on_non_working_day' => $dayType !== AttendanceDay::DAY_TYPE_NORMAL,
            'shift_template_id' => (int) ($this->rosterShiftTemplateId ?: 0),
            'policy_group_id' => (int) ($this->rosterPolicyGroupId ?: 0),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildEmptyCell(string $dayType, string $dayTypeLabel): array
    {
        $isNormal = $dayType === AttendanceDay::DAY_TYPE_NORMAL;

        return [
            'label' => $isNormal ? '-' : $dayTypeLabel,
            'state' => 'empty',
            'variant' => 'default',
            'policy' => '-',
            'title' => $isNormal
                ? __('No roster assignment')
                : __(':day — no assignment', ['day' => $dayTypeLabel]),
            'day_type' => $dayType,
            'day_type_label' => $dayTypeLabel,
            'on_non_working_day' => false,
            'shift_template_id' => 0,
            'policy_group_id' => 0,
        ];
    }

    private function dayTypeLabel(string $dayType): string
    {
        return DayTypeVocabulary::label($dayType);
    }

    /**
     * Add per-date markers — is_today, is_weekend, and whether this date is a
     * public holiday for at least one rendered employee — so the roster grid
     * can highlight columns without recomputing day types from row data.
     *
     * @param  list<array{date: string, day: string, label: string}>  $days
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return list<array{date: string, day: string, day_short: string, label: string, is_today: bool, is_weekend: bool, is_holiday: bool}>
     */
    private function enrichGridDays(array $days, Collection $rows): array
    {
        $today = CarbonImmutable::today()->toDateString();

        $holidayDates = [];
        foreach ($rows as $row) {
            foreach (($row['cells'] ?? []) as $date => $cell) {
                if (($cell['day_type'] ?? null) === AttendanceDay::DAY_TYPE_HOLIDAY) {
                    $holidayDates[$date] = true;
                }
            }
        }

        return array_map(function (array $day) use ($today, $holidayDates): array {
            $carbon = CarbonImmutable::parse($day['date']);
            $weekday = (int) $carbon->dayOfWeek; // 0 = Sunday, 6 = Saturday

            return [
                ...$day,
                'day_short' => substr($day['day'], 0, 1),
                'is_today' => $day['date'] === $today,
                'is_weekend' => $weekday === 0 || $weekday === 6,
                'is_holiday' => isset($holidayDates[$day['date']]),
            ];
        }, $days);
    }

    private function safeGridStartDate(): CarbonImmutable
    {
        try {
            return CarbonImmutable::parse($this->rosterEffectiveFrom);
        } catch (\Throwable) {
            return CarbonImmutable::parse(now()->toDateString());
        }
    }

    private function safeGridEndDate(CarbonImmutable $start): CarbonImmutable
    {
        try {
            $end = $this->rosterEffectiveTo === ''
                ? $start->addDays(6)
                : CarbonImmutable::parse($this->rosterEffectiveTo);
        } catch (\Throwable) {
            $end = $start->addDays(6);
        }

        if ($end->lessThan($start)) {
            return $start;
        }

        return $end->greaterThan($start->addDays(30)) ? $start->addDays(30) : $end;
    }

    /**
     * @param  Collection<int, AttendanceRosterAssignment>  $assignments
     */
    private function assignmentForGridDate(Collection $assignments, string $date): ?AttendanceRosterAssignment
    {
        return $assignments
            ->filter(fn (AttendanceRosterAssignment $assignment): bool => $assignment->effective_from?->toDateString() <= $date
                && ($assignment->effective_to === null || $assignment->effective_to->toDateString() >= $date))
            ->sortByDesc(fn (AttendanceRosterAssignment $assignment): string => $assignment->effective_from?->toDateString() ?? '')
            ->first();
    }

    private function shiftCodeForGrid(AttendanceRosterAssignment $assignment, string $date): string
    {
        $exception = $this->exceptionForGridDate($assignment, $date);
        if (is_array($exception) && filter_var($exception['attendance_shift_template_id'] ?? null, FILTER_VALIDATE_INT) !== false) {
            return AttendanceShiftTemplate::query()
                ->where('company_id', $this->companyId())
                ->whereKey((int) $exception['attendance_shift_template_id'])
                ->value('code') ?? '-';
        }

        $patternShift = $this->patternShiftCodeForGrid($assignment, $date);

        return $patternShift ?? $assignment->shiftTemplate?->code ?? '-';
    }
}
