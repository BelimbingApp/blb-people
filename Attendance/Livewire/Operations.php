<?php

namespace App\Modules\People\Attendance\Livewire;

use App\Base\Foundation\Livewire\Concerns\InteractsWithNotifications;
use App\Modules\People\Attendance\Livewire\Concerns\InteractsWithAttendanceScreen;
use App\Modules\People\Attendance\Models\AttendanceAbsenceBatch;
use App\Modules\People\Attendance\Models\AttendanceClockEvent;
use App\Modules\People\Attendance\Models\AttendanceDay;
use App\Modules\People\Attendance\Models\AttendanceOvertimeRequest;
use App\Modules\People\Attendance\Models\AttendancePolicyGroup;
use App\Modules\People\Attendance\Services\AttendanceLifecycleService;
use Illuminate\Contracts\View\View;
use Livewire\Component;

class Operations extends Component
{
    use InteractsWithAttendanceScreen;
    use InteractsWithNotifications;

    public string $search = '';

    public string $status = '';

    public function updatedSearch(): void
    {
        $this->search = trim($this->search);
    }

    public function finalizeDay(int $dayId): void
    {
        if (! $this->ensureSchemaReady()) {
            return;
        }

        $this->authorizeAttendance('people.attendance.manage');

        app(AttendanceLifecycleService::class)->finalize($this->attendanceDay($dayId));

        $this->notify(__('Attendance day finalized.'));
    }

    public function lockDay(int $dayId): void
    {
        if (! $this->ensureSchemaReady()) {
            return;
        }

        $this->authorizeAttendance('people.attendance.manage');

        app(AttendanceLifecycleService::class)->lock($this->attendanceDay($dayId));

        $this->notify(__('Attendance day locked.'));
    }

    public function render(): View
    {
        $companyId = $this->companyId();
        $schemaReady = $this->schemaReady();
        $canManage = $this->canAttendance('people.attendance.manage');
        $search = trim($this->search);

        return view('people-attendance::livewire.people.attendance.operations', [
            'schemaReady' => $schemaReady,
            'canManage' => $canManage,
            'canClock' => false,
            'surface' => 'operations',
            'currentEmployeeId' => null,
            'statusOptions' => $this->statusOptions(),
            'attendanceDays' => $schemaReady
                ? AttendanceDay::query()
                    ->where('company_id', $companyId)
                    ->with(['employee', 'shiftTemplate', 'policyGroup'])
                    ->when($this->status !== '', fn ($query) => $query->where('status', $this->status))
                    ->when($search !== '', fn ($query) => $query->whereHas('employee', fn ($employeeQuery) => $employeeQuery
                        ->where('employee_number', 'like', "%{$search}%")
                        ->orWhere('full_name', 'like', "%{$search}%")))
                    ->latest('attendance_date')
                    ->limit(80)
                    ->get()
                : collect(),
            'pendingOvertime' => $schemaReady
                ? AttendanceOvertimeRequest::query()
                    ->where('company_id', $companyId)
                    ->where('status', AttendanceOvertimeRequest::STATUS_SUBMITTED)
                    ->latest('submitted_at')
                    ->limit(40)
                    ->get()
                : collect(),
            'clockEvents' => $schemaReady
                ? AttendanceClockEvent::query()
                    ->where('company_id', $companyId)
                    ->with(['employee'])
                    ->latest('occurred_at')
                    ->limit(40)
                    ->get()
                : collect(),
            'absenceBatches' => $schemaReady
                ? AttendanceAbsenceBatch::query()
                    ->where('company_id', $companyId)
                    ->withCount('entries')
                    ->latest('period_starts_on')
                    ->limit(20)
                    ->get()
                : collect(),
            'policyGroups' => $schemaReady
                ? AttendancePolicyGroup::query()
                    ->where('company_id', $companyId)
                    ->orderBy('code')
                    ->get()
                : collect(),
        ]);
    }

    private function attendanceDay(int $dayId): AttendanceDay
    {
        return AttendanceDay::query()
            ->where('company_id', $this->companyId())
            ->findOrFail($dayId);
    }

    /** @return array<string, string> */
    private function statusOptions(): array
    {
        return [
            AttendanceDay::STATUS_SCHEDULED => __('Scheduled'),
            AttendanceDay::STATUS_IN_PROGRESS => __('In progress'),
            AttendanceDay::STATUS_EXCEPTION_PENDING => __('Exception pending'),
            AttendanceDay::STATUS_READY_FOR_REVIEW => __('Ready for review'),
            AttendanceDay::STATUS_FINALIZED => __('Finalized'),
            AttendanceDay::STATUS_EXPORTED_TO_PAYROLL => __('Exported to payroll'),
            AttendanceDay::STATUS_LOCKED => __('Locked'),
        ];
    }
}
