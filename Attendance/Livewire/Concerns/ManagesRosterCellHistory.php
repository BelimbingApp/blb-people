<?php

namespace App\Modules\People\Attendance\Livewire\Concerns;

use App\Base\Audit\Models\AuditMutation;
use App\Base\Authz\Enums\PrincipalType;
use App\Modules\Core\Employee\Models\Employee;
use App\Modules\Core\User\Models\User;

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

        $logs = AuditMutation::query()
            ->where('company_id', $companyId)
            ->where('subject_name', 'employee')
            ->where('subject_id', $employeeId)
            ->where('subject_identifier', $date)
            ->where('source', 'expanded')
            ->orderByDesc('occurred_at')
            ->limit(50)
            ->get();

        $userIds = $logs->where('actor_type', PrincipalType::USER->value)->pluck('actor_id')->filter()->unique()->all();
        $userNames = User::query()->whereKey($userIds)->pluck('name', 'id');

        $this->cellHistoryEmployeeId = $employeeId;
        $this->cellHistoryDate = $date;
        $this->cellHistoryEmployeeName = $employee->displayName();
        $this->cellHistoryRows = $logs->map(fn (AuditMutation $log): array => [
            'id' => $log->id,
            'action' => $log->event,
            'changed_at' => $log->occurred_at?->format('d M Y, H:i'),
            'changed_by' => $log->actor_type === PrincipalType::USER->value && $log->actor_id !== null
                ? ($userNames[$log->actor_id] ?? __('Unknown'))
                : __('System'),
            'prev_shift' => ($log->old_values ?? [])['shift_code'] ?? null,
            'prev_policy' => ($log->old_values ?? [])['policy_code'] ?? null,
            'new_shift' => ($log->new_values ?? [])['shift_code'] ?? null,
            'new_policy' => ($log->new_values ?? [])['policy_code'] ?? null,
            'note' => null,
            'job' => null,
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
