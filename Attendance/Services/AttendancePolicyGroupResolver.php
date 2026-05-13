<?php

namespace App\Modules\People\Attendance\Services;

use App\Modules\Core\Employee\Models\Employee;
use App\Modules\People\Attendance\Models\AttendancePolicyGroup;
use App\Modules\People\Attendance\Models\AttendanceRosterAssignment;
use DateTimeInterface;

class AttendancePolicyGroupResolver
{
    public function resolveForEmployee(Employee $employee, DateTimeInterface|string $date): ?AttendancePolicyGroup
    {
        $effectiveDate = is_string($date) ? $date : $date->format('Y-m-d');

        $assignment = AttendanceRosterAssignment::query()
            ->where('company_id', $employee->company_id)
            ->where(function ($query) use ($employee): void {
                $query->where('employee_id', $employee->id)
                    ->orWhereNull('employee_id');
            })
            ->where('effective_from', '<=', $effectiveDate)
            ->where(function ($query) use ($effectiveDate): void {
                $query->whereNull('effective_to')
                    ->orWhere('effective_to', '>=', $effectiveDate);
            })
            ->where('publish_state', 'published')
            ->with('policyGroup')
            ->orderByRaw('employee_id is null')
            ->latest('effective_from')
            ->first();

        return $assignment?->policyGroup;
    }
}
