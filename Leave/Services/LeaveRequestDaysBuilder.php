<?php

namespace App\Modules\People\Leave\Services;

use App\Modules\Core\Employee\Models\Employee;
use App\Modules\People\Leave\Data\LeaveDayBreakdown;
use App\Modules\People\Leave\Data\LeaveDaysPreview;
use App\Modules\People\Leave\Data\WorkCalendarDay;
use App\Modules\People\Leave\Models\LeaveRequest;
use App\Modules\People\Leave\Models\LeaveRequestDay;
use App\Modules\People\Leave\Models\LeaveRequestPolicy;
use App\Modules\People\Leave\Models\LeaveType;
use DateTimeImmutable;

class LeaveRequestDaysBuilder
{
    private const DOW_MAP = [
        0 => 'sun',
        1 => 'mon',
        2 => 'tue',
        3 => 'wed',
        4 => 'thu',
        5 => 'fri',
        6 => 'sat',
    ];

    public function __construct(
        private readonly WorkCalendarResolver $calendarResolver,
    ) {}

    /**
     * Build a day-by-day preview of a leave request honoring:
     *   - daytype exclusions from the request policy (holiday / off / rest)
     *   - day-of-week unit overrides (e.g. Saturday → half_day)
     *   - the requested unit (day / half_day / hour)
     */
    public function preview(
        Employee $employee,
        DateTimeImmutable $startsOn,
        DateTimeImmutable $endsOn,
        string $unit,
        ?float $hoursCount,
        LeaveRequestPolicy $policy,
        ?string $countryIso = null,
        ?string $stateCode = null,
        ?string $portionOverride = null,
    ): LeaveDaysPreview {
        $calendar = $this->calendarResolver->resolveRange($employee, $startsOn, $endsOn, $countryIso, $stateCode);

        $excludeHoliday = (bool) $policy->exclude_holiday_from_count;
        $excludeOffDay = (bool) $policy->exclude_off_day_from_count;
        $excludeRestDay = (bool) $policy->exclude_rest_day_from_count;
        $dowOverrides = $this->normalizeOverrides($policy->day_of_week_unit_overrides ?? []);

        $breakdowns = [];
        $totalDays = 0.0;
        $totalHours = 0.0;
        $warnings = [];

        foreach ($calendar as $day) {
            $counts = $this->shouldCount($day->daytype, $excludeHoliday, $excludeOffDay, $excludeRestDay);

            if (! $counts) {
                $breakdowns[] = new LeaveDayBreakdown(
                    occursOn: $day->occursOn,
                    daytype: $day->daytype,
                    portion: LeaveRequestDay::PORTION_FULL,
                    hoursCount: null,
                    countsAgainstBalance: false,
                    countedAsDays: 0.0,
                    daytypeLabel: $day->label,
                    note: 'Excluded by policy ('.$day->daytype.')',
                );

                continue;
            }

            $dowKey = self::DOW_MAP[(int) $day->occursOn->format('w')];
            $resolvedUnit = $unit;
            $resolvedPortion = $portionOverride ?? LeaveRequestDay::PORTION_FULL;
            $note = null;

            if (isset($dowOverrides[$dowKey])) {
                $override = $dowOverrides[$dowKey];
                if ($override === LeaveType::UNIT_HALF_DAY || $override === 'half_day') {
                    $resolvedUnit = LeaveRequest::UNIT_HALF_DAY;
                    $resolvedPortion = LeaveRequestDay::PORTION_AM;
                    $note = $dowKey.' override → half day';
                    $warnings[] = $day->occursOn->format('Y-m-d').': '.$note;
                } elseif ($override === 'full_day' || $override === 'day') {
                    $resolvedUnit = LeaveRequest::UNIT_DAY;
                    $resolvedPortion = LeaveRequestDay::PORTION_FULL;
                }
            }

            [$countedDays, $countedHours, $portion, $hours] = $this->quantify($resolvedUnit, $resolvedPortion, $hoursCount);

            $totalDays += $countedDays;
            $totalHours += $countedHours;

            $breakdowns[] = new LeaveDayBreakdown(
                occursOn: $day->occursOn,
                daytype: $day->daytype,
                portion: $portion,
                hoursCount: $hours,
                countsAgainstBalance: true,
                countedAsDays: $countedDays,
                daytypeLabel: $day->label,
                note: $note,
            );
        }

        if ($policy->max_days_per_application !== null && $totalDays > (float) $policy->max_days_per_application) {
            $warnings[] = sprintf(
                'Request exceeds max-days-per-application (%.2f > %.2f)',
                $totalDays,
                (float) $policy->max_days_per_application,
            );
        }

        return new LeaveDaysPreview($breakdowns, $totalDays, $totalHours, $warnings);
    }

    private function shouldCount(string $daytype, bool $exHoliday, bool $exOffDay, bool $exRestDay): bool
    {
        return match ($daytype) {
            WorkCalendarDay::DAYTYPE_HOLIDAY => ! $exHoliday,
            WorkCalendarDay::DAYTYPE_OFF_DAY => ! $exOffDay,
            WorkCalendarDay::DAYTYPE_REST_DAY => ! $exRestDay,
            default => true,
        };
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, string>
     */
    private function normalizeOverrides(array $overrides): array
    {
        $out = [];
        foreach ($overrides as $key => $value) {
            $out[strtolower((string) $key)] = (string) $value;
        }

        return $out;
    }

    /** @return array{0: float, 1: float, 2: string, 3: ?float} */
    private function quantify(string $unit, string $portion, ?float $hoursCount): array
    {
        return match ($unit) {
            LeaveRequest::UNIT_HALF_DAY => [0.5, 0.0, $portion === LeaveRequestDay::PORTION_FULL ? LeaveRequestDay::PORTION_AM : $portion, null],
            LeaveRequest::UNIT_HOUR => [0.0, $hoursCount ?? 0.0, LeaveRequestDay::PORTION_HOURS, $hoursCount],
            default => [1.0, 0.0, LeaveRequestDay::PORTION_FULL, null],
        };
    }
}
