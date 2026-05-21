<?php

namespace App\Modules\People\Attendance\Livewire\Concerns;

use App\Modules\Core\Employee\Models\Employee;
use App\Modules\Core\User\Models\User;
use App\Modules\People\Attendance\Models\AttendanceRosterCellLog;

trait ManagesRosterCellHistory
{
    public bool $cellHistoryOpen = false;

    /** @var list<array<string, mixed>> */
    public array $cellHistoryRows = [];

    public int $cellHistoryEmployeeId = 0;

    public string $cellHistoryDate = '';

    public string $cellHistoryEmployeeName = '';

    public function loadCellHistory(int $employeeId, string $date): void
    {
        $this->authorizeAttendance('people.attendance.manage');

        $companyId = $this->companyId();

        $employee = Employee::query()
            ->where('company_id', $companyId)
            ->whereKey($employeeId)
            ->first();

        if (! $employee instanceof Employee) {
            return;
        }

        $logs = AttendanceRosterCellLog::query()
            ->where('company_id', $companyId)
            ->where('employee_id', $employeeId)
            ->whereDate('date', $date)
            ->with(['previousShift', 'newShift', 'previousPolicy', 'newPolicy'])
            ->orderByDesc('changed_at')
            ->limit(50)
            ->get();

        $userIds = $logs->pluck('changed_by')->filter()->unique()->all();
        $userNames = User::query()->whereKey($userIds)->pluck('name', 'id');

        $this->cellHistoryEmployeeId = $employeeId;
        $this->cellHistoryDate = $date;
        $this->cellHistoryEmployeeName = $employee->displayName();
        $this->cellHistoryRows = $logs->map(fn (AttendanceRosterCellLog $log): array => [
            'id' => $log->id,
            'action' => $log->action,
            'changed_at' => $log->changed_at?->format('d M Y, H:i'),
            'changed_by' => $log->changed_by !== null
                ? ($userNames[$log->changed_by] ?? __('Unknown'))
                : __('System'),
            'prev_shift' => $log->previousShift?->code,
            'prev_policy' => $log->previousPolicy?->code,
            'new_shift' => $log->newShift?->code,
            'new_policy' => $log->newPolicy?->code,
            'note' => $log->note,
            'job' => $log->job,
        ])->all();

        $this->cellHistoryOpen = true;
    }

    public function closeCellHistory(): void
    {
        $this->cellHistoryOpen = false;
        $this->cellHistoryRows = [];
        $this->cellHistoryEmployeeId = 0;
        $this->cellHistoryDate = '';
        $this->cellHistoryEmployeeName = '';
    }
}
