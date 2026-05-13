<?php

namespace App\Modules\People\Attendance\Services;

use App\Modules\Core\Employee\Models\Employee;
use App\Modules\People\Attendance\Models\AttendanceDay;
use App\Modules\People\Attendance\Models\AttendanceRosterAssignment;
use App\Modules\People\Attendance\Models\AttendanceRosterPattern;
use App\Modules\People\Attendance\Models\AttendanceShiftTemplate;
use Carbon\CarbonImmutable;
use DateTimeInterface;

class AttendanceDayResolverService
{
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

        $assignment = $this->assignmentFor($employee, $attendanceDate);
        $shift = $this->shiftFor($assignment, $employee, $attendanceDate);
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
            'attendance_policy_group_id' => $assignment?->attendance_policy_group_id,
            'attendance_date' => $attendanceDate,
            'status' => AttendanceDay::STATUS_SCHEDULED,
            'day_type' => $shift === null ? 'off' : 'normal',
            'shift_starts_at' => $shiftStart,
            'shift_ends_at' => $shiftEnd,
            'expected_minutes' => $shift?->expected_work_minutes ?? 0,
            'payroll_period_date' => $attendanceDate,
            'metadata' => [
                'resolver' => 'attendance_day_resolver',
                'roster_revision' => $assignment?->revision,
            ],
        ]);
    }

    private function assignmentFor(Employee $employee, string $date): ?AttendanceRosterAssignment
    {
        return AttendanceRosterAssignment::query()
            ->where('company_id', $employee->company_id)
            ->where(function ($query) use ($employee): void {
                $query->where('employee_id', $employee->id)
                    ->orWhereNull('employee_id');
            })
            ->where('effective_from', '<=', $date)
            ->where(function ($query) use ($date): void {
                $query->whereNull('effective_to')
                    ->orWhere('effective_to', '>=', $date);
            })
            ->where('publish_state', 'published')
            ->with(['shiftTemplate', 'rosterPattern'])
            ->orderByRaw('employee_id is null')
            ->latest('effective_from')
            ->first();
    }

    private function shiftFor(?AttendanceRosterAssignment $assignment, Employee $employee, string $date): ?AttendanceShiftTemplate
    {
        if ($assignment === null) {
            return null;
        }

        $patternShift = $this->shiftFromPattern($assignment, $employee, $date);

        return $patternShift ?? $assignment->shiftTemplate;
    }

    private function shiftFromPattern(AttendanceRosterAssignment $assignment, Employee $employee, string $date): ?AttendanceShiftTemplate
    {
        $pattern = $assignment->rosterPattern;
        if (! $pattern instanceof AttendanceRosterPattern) {
            return null;
        }

        $definition = $pattern->pattern_definition ?? [];
        $shiftCode = null;

        if ($pattern->pattern_type === AttendanceRosterPattern::TYPE_FIXED_WEEKLY) {
            $weekday = strtolower(CarbonImmutable::parse($date)->englishDayOfWeek);
            $shiftCode = $definition['weekdays'][$weekday]['shift_code'] ?? $definition[$weekday]['shift_code'] ?? null;
        }

        if ($pattern->pattern_type === AttendanceRosterPattern::TYPE_ROTATING) {
            $cycleDays = max(1, (int) ($definition['cycle_days'] ?? 1));
            $offset = CarbonImmutable::parse($assignment->effective_from)->diffInDays(CarbonImmutable::parse($date)) % $cycleDays;
            foreach ($definition['days'] ?? [] as $day) {
                if ((int) ($day['offset'] ?? -1) === $offset) {
                    $shiftCode = $day['shift_code'] ?? null;
                    break;
                }
            }
        }

        if (! is_string($shiftCode) || $shiftCode === '') {
            return null;
        }

        return AttendanceShiftTemplate::query()
            ->where('company_id', $employee->company_id)
            ->where('code', $shiftCode)
            ->where('status', AttendanceShiftTemplate::STATUS_ACTIVE)
            ->where('effective_from', '<=', $date)
            ->where(function ($query) use ($date): void {
                $query->whereNull('effective_to')
                    ->orWhere('effective_to', '>=', $date);
            })
            ->first();
    }
}
