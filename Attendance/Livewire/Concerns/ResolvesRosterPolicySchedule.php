<?php

namespace App\Modules\People\Attendance\Livewire\Concerns;

use App\Modules\People\Attendance\Models\AttendancePolicyGroup;
use App\Modules\People\Attendance\Models\AttendanceRosterAssignment;
use App\Modules\People\Attendance\Models\AttendanceRosterPattern;
use App\Modules\People\Attendance\Models\AttendanceShiftTemplate;
use Carbon\CarbonImmutable;

trait ResolvesRosterPolicySchedule
{
    /**
     * @return array<string, mixed>|null
     */
    private function exceptionForGridDate(AttendanceRosterAssignment $assignment, string $date): ?array
    {
        foreach ($assignment->exceptions ?? [] as $exception) {
            if (is_array($exception) && ($exception['date'] ?? null) === $date) {
                return $exception;
            }
        }

        return null;
    }

    private function patternShiftCodeForGrid(AttendanceRosterAssignment $assignment, string $date): ?string
    {
        $pattern = $assignment->rosterPattern;
        if (! $pattern instanceof AttendanceRosterPattern) {
            return null;
        }

        $definition = $pattern->pattern_definition ?? [];

        $shiftCode = match ($pattern->pattern_type) {
            AttendanceRosterPattern::TYPE_FIXED_WEEKLY => $definition['weekdays'][strtolower(CarbonImmutable::parse($date)->englishDayOfWeek)]['shift_code'] ?? null,
            AttendanceRosterPattern::TYPE_ROTATING => $this->rotatingPatternShiftCodeForGrid($definition, (string) $assignment->effective_from, $date),
            default => null,
        };

        return is_string($shiftCode) && $shiftCode !== '' ? $shiftCode : null;
    }

    /**
     * @param  array<string, mixed>  $definition
     */
    private function rotatingPatternShiftCodeForGrid(array $definition, string $effectiveFrom, string $date): ?string
    {
        $cycleDays = max(1, (int) ($definition['cycle_days'] ?? 1));
        $offset = CarbonImmutable::parse($effectiveFrom)->diffInDays(CarbonImmutable::parse($date)) % $cycleDays;

        foreach ($definition['days'] ?? [] as $day) {
            if ((int) ($day['offset'] ?? -1) === $offset && is_string($day['shift_code'] ?? null)) {
                return $day['shift_code'];
            }
        }

        return null;
    }

    private function activeShiftTemplateForDate(mixed $shiftTemplateId, string $date): ?AttendanceShiftTemplate
    {
        if (filter_var($shiftTemplateId, FILTER_VALIDATE_INT) === false) {
            return null;
        }

        return AttendanceShiftTemplate::query()
            ->where('company_id', $this->companyId())
            ->whereKey((int) $shiftTemplateId)
            ->where('status', AttendanceShiftTemplate::STATUS_ACTIVE)
            ->whereDate('effective_from', '<=', $date)
            ->where(function ($query) use ($date): void {
                $query->whereNull('effective_to')
                    ->orWhereDate('effective_to', '>=', $date);
            })
            ->first();
    }

    private function activePolicyGroupForDate(mixed $policyGroupId, string $date): ?AttendancePolicyGroup
    {
        if (filter_var($policyGroupId, FILTER_VALIDATE_INT) === false) {
            return null;
        }

        return AttendancePolicyGroup::query()
            ->where('company_id', $this->companyId())
            ->whereKey((int) $policyGroupId)
            ->where('status', AttendancePolicyGroup::STATUS_ACTIVE)
            ->whereDate('effective_from', '<=', $date)
            ->where(function ($query) use ($date): void {
                $query->whereNull('effective_to')
                    ->orWhereDate('effective_to', '>=', $date);
            })
            ->first();
    }

    private function assignmentForEmployeeDate(int $employeeId, string $date): ?AttendanceRosterAssignment
    {
        return AttendanceRosterAssignment::query()
            ->where('company_id', $this->companyId())
            ->where('employee_id', $employeeId)
            ->whereDate('effective_from', '<=', $date)
            ->where(function ($query) use ($date): void {
                $query->whereNull('effective_to')
                    ->orWhereDate('effective_to', '>=', $date);
            })
            ->latest('effective_from')
            ->first();
    }
}
