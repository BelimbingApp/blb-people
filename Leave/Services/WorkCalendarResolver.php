<?php

namespace App\Modules\People\Leave\Services;

use App\Modules\Core\Employee\Models\Employee;
use App\Modules\People\Leave\Data\WorkCalendarDay;
use App\Modules\People\Leave\Services\LeaveCountryPackRegistry;
use App\Modules\People\Settings\Models\EmployeeWorkProfile;
use App\Modules\People\Settings\Models\PeopleCalendarException;
use DateTimeImmutable;

class WorkCalendarResolver
{
    /**
     * `kind` values on PeopleCalendarException that map to daytype overrides.
     * Anything else falls through to working day.
     */
    private const KIND_DAYTYPE_MAP = [
        'holiday' => WorkCalendarDay::DAYTYPE_HOLIDAY,
        'public_holiday' => WorkCalendarDay::DAYTYPE_HOLIDAY,
        'non_working_day' => WorkCalendarDay::DAYTYPE_HOLIDAY,
        'rest_day' => WorkCalendarDay::DAYTYPE_REST_DAY,
        'off_day' => WorkCalendarDay::DAYTYPE_OFF_DAY,
        'company_holiday' => WorkCalendarDay::DAYTYPE_HOLIDAY,
    ];

    public function __construct(
        private readonly LeaveCountryPackRegistry $packRegistry,
    ) {}

    /**
     * Resolve daytypes for the inclusive range [$from, $to] for an employee.
     *
     * Precedence: company calendar exception > country-pack public holiday > working day.
     *
     * @return array<string, WorkCalendarDay> Keyed by Y-m-d.
     */
    public function resolveRange(Employee $employee, DateTimeImmutable $from, DateTimeImmutable $to, ?string $countryIso = null, ?string $stateCode = null): array
    {
        if ($to < $from) {
            return [];
        }

        $workCalendarId = $this->workCalendarIdFor($employee);
        $exceptions = $this->loadExceptions($workCalendarId, $from, $to);
        $holidaysByDate = $this->loadHolidays($countryIso, $stateCode, $from, $to);

        $days = [];
        $cursor = $from;
        while ($cursor <= $to) {
            $key = $cursor->format('Y-m-d');

            if (isset($exceptions[$key])) {
                $row = $exceptions[$key];
                $daytype = self::KIND_DAYTYPE_MAP[$row['kind']] ?? WorkCalendarDay::DAYTYPE_HOLIDAY;
                $days[$key] = new WorkCalendarDay($cursor, $daytype, $row['name'], 'calendar_exception');
            } elseif (isset($holidaysByDate[$key])) {
                $holiday = $holidaysByDate[$key];
                $days[$key] = new WorkCalendarDay($cursor, WorkCalendarDay::DAYTYPE_HOLIDAY, $holiday->name, 'country_pack');
            } else {
                $days[$key] = new WorkCalendarDay($cursor, WorkCalendarDay::DAYTYPE_WORKING);
            }

            $cursor = $cursor->modify('+1 day');
        }

        return $days;
    }

    private function workCalendarIdFor(Employee $employee): ?int
    {
        $profile = EmployeeWorkProfile::query()
            ->where('employee_id', $employee->getKey())
            ->orderByDesc('id')
            ->first();

        return $profile?->work_calendar_id;
    }

    /** @return array<string, array{kind: string, name: string}> */
    private function loadExceptions(?int $workCalendarId, DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        if ($workCalendarId === null) {
            return [];
        }

        $rows = PeopleCalendarException::query()
            ->where('work_calendar_id', $workCalendarId)
            ->whereBetween('occurs_on', [$from->format('Y-m-d'), $to->format('Y-m-d')])
            ->get(['occurs_on', 'kind', 'name']);

        $byDate = [];
        foreach ($rows as $row) {
            $byDate[$row->occurs_on->format('Y-m-d')] = [
                'kind' => $row->kind,
                'name' => $row->name,
            ];
        }

        return $byDate;
    }

    /** @return array<string, \App\Modules\People\Leave\Data\PublicHoliday> */
    private function loadHolidays(?string $countryIso, ?string $stateCode, DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        if ($countryIso === null || ! $this->packRegistry->hasCountry($countryIso)) {
            return [];
        }

        $pack = $this->packRegistry->forCountry($countryIso);
        $years = range((int) $from->format('Y'), (int) $to->format('Y'));

        $byDate = [];
        foreach ($years as $year) {
            foreach ($pack->publicHolidayCalendar()->publicHolidaysForYear($year, $stateCode) as $holiday) {
                if ($holiday->occursOn >= $from && $holiday->occursOn <= $to) {
                    $byDate[$holiday->occursOn->format('Y-m-d')] = $holiday;
                }
            }
        }

        return $byDate;
    }
}
