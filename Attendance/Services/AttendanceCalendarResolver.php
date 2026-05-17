<?php

namespace App\Modules\People\Attendance\Services;

use App\Modules\Core\Employee\Models\Employee;
use App\Modules\People\Attendance\Models\AttendanceDay;
use App\Modules\People\Settings\Models\EmployeeWorkProfile;
use App\Modules\People\Settings\Models\PeopleCalendarException;
use App\Modules\People\Settings\Models\PeopleReferenceEntry;
use Carbon\CarbonImmutable;
use DateTimeInterface;

/**
 * Resolves an employee's day type (Normal / Rest / Off / Holiday) for a given date.
 *
 * Sources, in priority order:
 * 1. `PeopleCalendarException` rows on the employee's work calendar with `kind` of
 *    `public_holiday` or `non_working_day` → Holiday.
 * 2. The work calendar's `metadata.rest_days` array (English day-of-week names) → Rest.
 * 3. The work calendar's `metadata.off_days` array → Off.
 * 4. Otherwise → Normal.
 *
 * Calendar metadata schema (stored on `PeopleReferenceEntry.metadata` for entries of
 * type `work_calendar`):
 *
 *   {
 *     "rest_days": ["sunday"],
 *     "off_days": ["saturday"]
 *   }
 *
 * Missing metadata defaults to no rest/off days; an employee with no work calendar
 * resolves to Normal for every date that has no holiday exception.
 */
class AttendanceCalendarResolver
{
    private const HOLIDAY_KINDS = ['public_holiday', 'non_working_day'];

    /** @var array<int, ?int> */
    private array $workCalendarCache = [];

    /** @var array<int, array<string, mixed>> */
    private array $metadataCache = [];

    /** @var array<string, bool>|null */
    private ?array $holidayLookup = null;

    private ?string $holidayLookupStart = null;

    private ?string $holidayLookupEnd = null;

    /**
     * Warm the per-employee work-calendar cache and per-(calendar, date) holiday
     * lookup so subsequent `dayType()` calls for the same render pass don't issue
     * one query per cell. Pass the employees whose rows you'll render and the
     * date range that will be displayed; the resolver pre-fetches in two
     * grouped queries instead of N queries.
     *
     * @param  iterable<Employee>  $employees
     */
    public function preload(iterable $employees, DateTimeInterface|string $start, DateTimeInterface|string $end): void
    {
        $startDate = CarbonImmutable::parse($start)->toDateString();
        $endDate = CarbonImmutable::parse($end)->toDateString();
        $employeeIds = [];

        foreach ($employees as $employee) {
            if ($employee instanceof Employee) {
                $employeeIds[] = $employee->id;
            }
        }

        if ($employeeIds === []) {
            $this->holidayLookup = $this->holidayLookup ?? [];

            return;
        }

        $profiles = EmployeeWorkProfile::query()
            ->whereIn('employee_id', $employeeIds)
            ->orderByDesc('hired_on')
            ->get(['employee_id', 'work_calendar_id']);

        $calendarIds = [];
        foreach ($profiles as $profile) {
            $employeeId = (int) $profile->employee_id;
            if (array_key_exists($employeeId, $this->workCalendarCache)) {
                continue;
            }
            $calendarId = $profile->work_calendar_id === null ? null : (int) $profile->work_calendar_id;
            $this->workCalendarCache[$employeeId] = $calendarId;
            if ($calendarId !== null) {
                $calendarIds[$calendarId] = true;
            }
        }

        foreach ($employeeIds as $employeeId) {
            if (! array_key_exists($employeeId, $this->workCalendarCache)) {
                $this->workCalendarCache[$employeeId] = null;
            }
        }

        $this->holidayLookup = $this->holidayLookup ?? [];
        $this->holidayLookupStart = $this->holidayLookupStart === null || $startDate < $this->holidayLookupStart
            ? $startDate
            : $this->holidayLookupStart;
        $this->holidayLookupEnd = $this->holidayLookupEnd === null || $endDate > $this->holidayLookupEnd
            ? $endDate
            : $this->holidayLookupEnd;

        if ($calendarIds === []) {
            return;
        }

        $exceptions = PeopleCalendarException::query()
            ->whereIn('work_calendar_id', array_keys($calendarIds))
            ->whereBetween('occurs_on', [$startDate, $endDate])
            ->whereIn('kind', self::HOLIDAY_KINDS)
            ->get(['work_calendar_id', 'occurs_on']);

        foreach ($exceptions as $exception) {
            $key = ((int) $exception->work_calendar_id).'|'.CarbonImmutable::parse($exception->occurs_on)->toDateString();
            $this->holidayLookup[$key] = true;
        }

        $missingMetadataIds = array_diff(array_keys($calendarIds), array_keys($this->metadataCache));
        if ($missingMetadataIds !== []) {
            $rows = PeopleReferenceEntry::query()
                ->whereIn('id', $missingMetadataIds)
                ->get(['id', 'metadata']);

            foreach ($rows as $row) {
                $this->metadataCache[(int) $row->id] = is_array($row->metadata) ? $row->metadata : [];
            }

            foreach ($missingMetadataIds as $calendarId) {
                if (! array_key_exists($calendarId, $this->metadataCache)) {
                    $this->metadataCache[$calendarId] = [];
                }
            }
        }
    }

    public function dayType(Employee $employee, DateTimeInterface|string $date): string
    {
        $attendanceDate = CarbonImmutable::parse($date);
        $workCalendarId = $this->workCalendarFor($employee);

        if ($workCalendarId === null) {
            return AttendanceDay::DAY_TYPE_NORMAL;
        }

        if ($this->isHoliday($workCalendarId, $attendanceDate)) {
            return AttendanceDay::DAY_TYPE_HOLIDAY;
        }

        $weekday = strtolower($attendanceDate->englishDayOfWeek);
        $metadata = $this->metadataFor($workCalendarId);

        if (in_array($weekday, $this->lowercaseList($metadata['rest_days'] ?? []), true)) {
            return AttendanceDay::DAY_TYPE_REST;
        }

        if (in_array($weekday, $this->lowercaseList($metadata['off_days'] ?? []), true)) {
            return AttendanceDay::DAY_TYPE_OFF;
        }

        return AttendanceDay::DAY_TYPE_NORMAL;
    }

    private function workCalendarFor(Employee $employee): ?int
    {
        if (array_key_exists($employee->id, $this->workCalendarCache)) {
            return $this->workCalendarCache[$employee->id];
        }

        $workCalendarId = EmployeeWorkProfile::query()
            ->where('employee_id', $employee->id)
            ->orderByDesc('hired_on')
            ->value('work_calendar_id');

        return $this->workCalendarCache[$employee->id] = $workCalendarId === null ? null : (int) $workCalendarId;
    }

    private function isHoliday(int $workCalendarId, CarbonImmutable $date): bool
    {
        $dateString = $date->toDateString();

        if (
            $this->holidayLookup !== null
            && $this->holidayLookupStart !== null
            && $this->holidayLookupEnd !== null
            && $dateString >= $this->holidayLookupStart
            && $dateString <= $this->holidayLookupEnd
        ) {
            return isset($this->holidayLookup[$workCalendarId.'|'.$dateString]);
        }

        return PeopleCalendarException::query()
            ->where('work_calendar_id', $workCalendarId)
            ->whereDate('occurs_on', $dateString)
            ->whereIn('kind', self::HOLIDAY_KINDS)
            ->exists();
    }

    /**
     * @return array<string, mixed>
     */
    private function metadataFor(int $workCalendarId): array
    {
        if (array_key_exists($workCalendarId, $this->metadataCache)) {
            return $this->metadataCache[$workCalendarId];
        }

        $metadata = PeopleReferenceEntry::query()
            ->whereKey($workCalendarId)
            ->value('metadata');

        return $this->metadataCache[$workCalendarId] = is_array($metadata) ? $metadata : [];
    }

    /**
     * @param  array<int, mixed>  $values
     * @return list<string>
     */
    private function lowercaseList(array $values): array
    {
        $out = [];
        foreach ($values as $value) {
            if (is_string($value) && $value !== '') {
                $out[] = strtolower($value);
            }
        }

        return $out;
    }
}
