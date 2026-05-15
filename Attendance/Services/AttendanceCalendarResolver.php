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
        return PeopleCalendarException::query()
            ->where('work_calendar_id', $workCalendarId)
            ->whereDate('occurs_on', $date->toDateString())
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
