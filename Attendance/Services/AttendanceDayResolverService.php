<?php

namespace App\Modules\People\Attendance\Services;

use App\Modules\Core\Employee\Models\Employee;
use App\Modules\People\Attendance\Models\AttendanceDay;
use App\Modules\People\Attendance\Models\AttendancePolicyGroup;
use App\Modules\People\Attendance\Models\AttendanceRosterAssignment;
use App\Modules\People\Attendance\Models\AttendanceRosterPattern;
use App\Modules\People\Attendance\Models\AttendanceShiftTemplate;
use Carbon\CarbonImmutable;
use DateTimeInterface;

class AttendanceDayResolverService
{
    public function __construct(
        private readonly AttendanceCalendarResolver $calendarResolver,
    ) {}

    public function resolve(Employee $employee, DateTimeInterface|string $date): AttendanceDay
    {
        $attendanceDate = CarbonImmutable::parse($date)->toDateString();
        $existing = AttendanceDay::query()
            ->where('employee_id', $employee->id)
            ->whereDate('attendance_date', $attendanceDate)
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        $dayType = $this->calendarResolver->dayType($employee, $attendanceDate);
        $assignment = $this->assignmentFor($employee, $attendanceDate);
        $shift = $this->shiftFor($assignment, $employee, $attendanceDate, $dayType);
        $policyGroupId = $this->policyGroupIdFor($assignment, $employee, $attendanceDate);
        $shiftStart = $shift === null ? null : CarbonImmutable::parse($attendanceDate.' '.$shift->starts_at);
        $shiftEnd = $shift === null ? null : CarbonImmutable::parse($attendanceDate.' '.$shift->ends_at);
        if ($shift?->crosses_midnight) {
            $shiftEnd = $shiftEnd?->addDay();
        }

        return AttendanceDay::query()->create([
            'company_id' => $employee->company_id,
            'employee_id' => $employee->id,
            'attendance_roster_assignment_id' => $assignment?->id,
            'attendance_shift_template_id' => $shift?->id,
            'attendance_policy_group_id' => $policyGroupId,
            'attendance_date' => $attendanceDate,
            'status' => AttendanceDay::STATUS_SCHEDULED,
            'day_type' => $dayType,
            'shift_starts_at' => $shiftStart,
            'shift_ends_at' => $shiftEnd,
            'expected_minutes' => $shift?->expected_work_minutes ?? 0,
            'payroll_period_date' => $this->payrollPeriodDate($shift, $attendanceDate),
            'metadata' => [
                'resolver' => 'attendance_day_resolver',
                'roster_revision' => $assignment?->revision,
            ],
        ]);
    }

    /**
     * Returns the date payroll should attribute the day to.
     *
     * For a same-day shift, that's the attendance date itself. For a cross-midnight shift,
     * `shift.cross_midnight_attribution` decides: `shift_start_date` keeps the start date
     * (default), `shift_end_date` rolls forward by one day so the worked hours land in the
     * period that contains the clock-out.
     */
    private function payrollPeriodDate(?AttendanceShiftTemplate $shift, string $attendanceDate): string
    {
        if ($shift === null || ! $shift->crosses_midnight) {
            return $attendanceDate;
        }

        if ($shift->cross_midnight_attribution === 'shift_end_date') {
            return CarbonImmutable::parse($attendanceDate)->addDay()->toDateString();
        }

        return $attendanceDate;
    }

    private function assignmentFor(Employee $employee, string $date): ?AttendanceRosterAssignment
    {
        return AttendanceRosterAssignment::query()
            ->where('company_id', $employee->company_id)
            ->where(function ($query) use ($employee): void {
                $query->where('employee_id', $employee->id)
                    ->orWhereNull('employee_id');
            })
            ->whereDate('effective_from', '<=', $date)
            ->where(function ($query) use ($date): void {
                $query->whereNull('effective_to')
                    ->orWhereDate('effective_to', '>=', $date);
            })
            ->with(['shiftTemplate', 'rosterPattern'])
            ->orderByRaw('employee_id is null')
            ->latest('effective_from')
            ->first();
    }

    private function shiftFor(?AttendanceRosterAssignment $assignment, Employee $employee, string $date, string $dayType): ?AttendanceShiftTemplate
    {
        if ($assignment === null) {
            return null;
        }

        $exceptionShift = $this->shiftFromAssignmentException($assignment, $employee, $date);
        if ($exceptionShift instanceof AttendanceShiftTemplate) {
            return $exceptionShift;
        }

        $patternShift = $this->shiftFromPattern($assignment, $employee, $date, $dayType);

        return $patternShift ?? $assignment->shiftTemplate;
    }

    private function shiftFromAssignmentException(AttendanceRosterAssignment $assignment, Employee $employee, string $date): ?AttendanceShiftTemplate
    {
        foreach ($assignment->exceptions ?? [] as $exception) {
            if (! is_array($exception) || ($exception['date'] ?? null) !== $date) {
                continue;
            }

            if (filter_var($exception['attendance_shift_template_id'] ?? null, FILTER_VALIDATE_INT) === false) {
                return null;
            }

            return AttendanceShiftTemplate::query()
                ->where('company_id', $employee->company_id)
                ->whereKey((int) $exception['attendance_shift_template_id'])
                ->where('status', AttendanceShiftTemplate::STATUS_ACTIVE)
                ->whereDate('effective_from', '<=', $date)
                ->where(function ($query) use ($date): void {
                    $query->whereNull('effective_to')
                        ->orWhereDate('effective_to', '>=', $date);
                })
                ->first();
        }

        return null;
    }

    private function policyGroupIdFor(?AttendanceRosterAssignment $assignment, Employee $employee, string $date): ?int
    {
        if ($assignment === null) {
            return null;
        }

        foreach ($assignment->exceptions ?? [] as $exception) {
            if (is_array($exception)
                && ($exception['date'] ?? null) === $date
                && filter_var($exception['attendance_policy_group_id'] ?? null, FILTER_VALIDATE_INT) !== false) {
                $policyGroupId = $this->activePolicyGroupIdForDate($employee->company_id, (int) $exception['attendance_policy_group_id'], $date);
                if ($policyGroupId !== null) {
                    return $policyGroupId;
                }
            }
        }

        return $assignment->attendance_policy_group_id;
    }

    private function activePolicyGroupIdForDate(int $companyId, int $policyGroupId, string $date): ?int
    {
        return AttendancePolicyGroup::query()
            ->where('company_id', $companyId)
            ->whereKey($policyGroupId)
            ->where('status', AttendancePolicyGroup::STATUS_ACTIVE)
            ->whereDate('effective_from', '<=', $date)
            ->where(function ($query) use ($date): void {
                $query->whereNull('effective_to')
                    ->orWhereDate('effective_to', '>=', $date);
            })
            ->value('id');
    }

    private function shiftFromPattern(AttendanceRosterAssignment $assignment, Employee $employee, string $date, string $dayType): ?AttendanceShiftTemplate
    {
        $pattern = $assignment->rosterPattern;
        if (! $pattern instanceof AttendanceRosterPattern) {
            return null;
        }

        $definition = $pattern->pattern_definition ?? [];

        // Day-type routing wins over weekday routing — e.g., "Monday → DAY, but if Monday is a holiday → HOLIDAY_HALF".
        // A day_type entry with shift_code set to null means "no shift on this day type."
        $dayTypeShiftCode = $this->shiftCodeFromDayType($definition, $dayType);
        if ($dayTypeShiftCode !== false) {
            return $dayTypeShiftCode === null ? null : $this->shiftByCode($employee->company_id, $dayTypeShiftCode, $date);
        }

        $shiftCode = match ($pattern->pattern_type) {
            AttendanceRosterPattern::TYPE_FIXED_WEEKLY => $this->shiftCodeFromFixedWeeklyPattern($definition, $date),
            AttendanceRosterPattern::TYPE_ROTATING => $this->shiftCodeFromRotatingPattern($definition, (string) $assignment->effective_from, $date),
            default => null,
        };

        if (! is_string($shiftCode) || $shiftCode === '') {
            return null;
        }

        return $this->shiftByCode($employee->company_id, $shiftCode, $date);
    }

    /** @param  array<string, mixed>  $definition */
    private function shiftCodeFromDayType(array $definition, string $dayType): string|null|false
    {
        if (! is_array($definition['day_types'] ?? null) || ! array_key_exists($dayType, $definition['day_types'])) {
            return false;
        }

        $entry = $definition['day_types'][$dayType];
        if (! is_array($entry) || ! array_key_exists('shift_code', $entry)) {
            return false;
        }

        $code = $entry['shift_code'];

        return $code === null ? null : (string) $code;
    }

    /** @param  array<string, mixed>  $definition */
    private function shiftCodeFromFixedWeeklyPattern(array $definition, string $date): mixed
    {
        $weekday = strtolower(CarbonImmutable::parse($date)->englishDayOfWeek);

        return $definition['weekdays'][$weekday]['shift_code'] ?? $definition[$weekday]['shift_code'] ?? null;
    }

    /** @param  array<string, mixed>  $definition */
    private function shiftCodeFromRotatingPattern(array $definition, string $effectiveFrom, string $date): mixed
    {
        $cycleDays = max(1, (int) ($definition['cycle_days'] ?? 1));
        $offset = CarbonImmutable::parse($effectiveFrom)->diffInDays(CarbonImmutable::parse($date)) % $cycleDays;

        foreach ($definition['days'] ?? [] as $day) {
            if ((int) ($day['offset'] ?? -1) === $offset) {
                return $day['shift_code'] ?? null;
            }
        }

        return null;
    }

    private function shiftByCode(int $companyId, string $shiftCode, string $date): ?AttendanceShiftTemplate
    {
        return AttendanceShiftTemplate::query()
            ->where('company_id', $companyId)
            ->where('code', $shiftCode)
            ->where('status', AttendanceShiftTemplate::STATUS_ACTIVE)
            ->whereDate('effective_from', '<=', $date)
            ->where(function ($query) use ($date): void {
                $query->whereNull('effective_to')
                    ->orWhereDate('effective_to', '>=', $date);
            })
            ->first();
    }
}
