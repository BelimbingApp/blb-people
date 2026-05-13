<?php

namespace App\Modules\People\Attendance\Livewire;

use App\Base\Authz\Contracts\AuthorizationService;
use App\Base\Authz\DTO\Actor;
use App\Modules\Core\Company\Models\Company;
use App\Modules\Core\Employee\Models\Employee;
use App\Modules\People\Attendance\Models\AttendanceAbsenceBatch;
use App\Modules\People\Attendance\Models\AttendanceAllowanceRule;
use App\Modules\People\Attendance\Models\AttendanceClockEvent;
use App\Modules\People\Attendance\Models\AttendanceDay;
use App\Modules\People\Attendance\Models\AttendanceGeofence;
use App\Modules\People\Attendance\Models\AttendanceGeofenceGroup;
use App\Modules\People\Attendance\Models\AttendanceOvertimeRequest;
use App\Modules\People\Attendance\Models\AttendancePolicyGroup;
use App\Modules\People\Attendance\Models\AttendanceRosterAssignment;
use App\Modules\People\Attendance\Models\AttendanceRosterPattern;
use App\Modules\People\Attendance\Models\AttendanceShiftTemplate;
use App\Modules\People\Attendance\Services\AttendanceDayProjectionService;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

class Index extends Component
{
    public string $surface = 'my';

    public string $search = '';

    public string $status = '';

    public function mount(?string $surface = null): void
    {
        $this->surface = in_array($surface, ['my', 'approvals', 'operations', 'settings'], true) ? $surface : 'my';
    }

    public function updatedSearch(): void
    {
        $this->search = trim($this->search);
    }

    public function clock(string $eventType): void
    {
        if (! in_array($eventType, [AttendanceClockEvent::TYPE_IN, AttendanceClockEvent::TYPE_OUT], true)) {
            return;
        }

        app(AuthorizationService::class)->authorize(
            Actor::forUser(Auth::user()),
            'people.attendance.clock',
        );

        $employeeId = $this->currentEmployeeId();
        if ($employeeId === null) {
            session()->flash('error', __('Your user account is not linked to an employee record.'));

            return;
        }

        $employee = Employee::query()
            ->where('company_id', $this->companyId())
            ->findOrFail($employeeId);

        $day = AttendanceDay::query()->firstOrCreate(
            [
                'employee_id' => $employee->id,
                'attendance_date' => now()->toDateString(),
            ],
            [
                'company_id' => $employee->company_id,
                'status' => AttendanceDay::STATUS_IN_PROGRESS,
                'day_type' => 'normal',
                'expected_minutes' => 480,
                'metadata' => ['source' => 'web-clock'],
            ],
        );

        AttendanceClockEvent::query()->create([
            'company_id' => $employee->company_id,
            'employee_id' => $employee->id,
            'attendance_day_id' => $day->id,
            'event_type' => $eventType,
            'occurred_at' => now(),
            'source' => AttendanceClockEvent::SOURCE_WEB,
            'actor_user_id' => Auth::id(),
            'ip_address' => request()->ip(),
            'metadata' => ['surface' => 'people.attendance.index'],
        ]);

        app(AttendanceDayProjectionService::class)->project($day)->save();

        session()->flash('success', __('Clock event recorded.'));
    }

    public function render(): View
    {
        $companyId = $this->companyId();
        $currentEmployeeId = $this->currentEmployeeId();
        $search = trim($this->search);
        $actor = Actor::forUser(Auth::user());
        $authz = app(AuthorizationService::class);
        $canManage = $authz->can($actor, 'people.attendance.manage')->allowed;
        $canApprove = $authz->can($actor, 'people.attendance.approve')->allowed;
        $canClock = $authz->can($actor, 'people.attendance.clock')->allowed;

        $surfaceTitle = match ($this->surface) {
            'approvals' => __('Attendance Approvals'),
            'operations' => __('Attendance Operations'),
            'settings' => __('Attendance Settings'),
            default => __('My Attendance'),
        };

        $surfaceSubtitle = match ($this->surface) {
            'approvals' => __('Review overtime and attendance exceptions before they affect payroll.'),
            'operations' => __('Review timecards, absenteeism batches, clock events, and payroll handoff readiness.'),
            'settings' => __('Maintain shift templates, policy groups, allowances, rosters, and clock-source controls.'),
            default => __('Review your timecard and record web clock events where enabled.'),
        };

        return view('livewire.people.attendance.index', [
            'surface' => $this->surface,
            'surfaceTitle' => $surfaceTitle,
            'surfaceSubtitle' => $surfaceSubtitle,
            'canManage' => $canManage,
            'canApprove' => $canApprove,
            'canClock' => $canClock,
            'currentEmployeeId' => $currentEmployeeId,
            'attendanceDays' => AttendanceDay::query()
                ->where('company_id', $companyId)
                ->with(['employee', 'shiftTemplate', 'policyGroup'])
                ->when($this->surface === 'my' && $currentEmployeeId !== null, fn ($query) => $query->where('employee_id', $currentEmployeeId))
                ->when($this->status !== '', fn ($query) => $query->where('status', $this->status))
                ->when($search !== '', fn ($query) => $query->whereHas('employee', fn ($employeeQuery) => $employeeQuery
                    ->where('employee_number', 'like', "%{$search}%")
                    ->orWhere('full_name', 'like', "%{$search}%")))
                ->latest('attendance_date')
                ->limit(80)
                ->get(),
            'pendingOvertime' => AttendanceOvertimeRequest::query()
                ->where('company_id', $companyId)
                ->where('status', AttendanceOvertimeRequest::STATUS_SUBMITTED)
                ->with(['employee', 'attendanceDay'])
                ->latest('submitted_at')
                ->limit(40)
                ->get(),
            'clockEvents' => AttendanceClockEvent::query()
                ->where('company_id', $companyId)
                ->with(['employee'])
                ->latest('occurred_at')
                ->limit(40)
                ->get(),
            'absenceBatches' => AttendanceAbsenceBatch::query()
                ->where('company_id', $companyId)
                ->withCount('entries')
                ->latest('period_starts_on')
                ->limit(20)
                ->get(),
            'shiftTemplates' => AttendanceShiftTemplate::query()
                ->where('company_id', $companyId)
                ->with('punchWindows')
                ->orderBy('code')
                ->get(),
            'policyGroups' => AttendancePolicyGroup::query()
                ->where('company_id', $companyId)
                ->with('allowanceRules')
                ->orderBy('code')
                ->get(),
            'allowanceRules' => AttendanceAllowanceRule::query()
                ->where('company_id', $companyId)
                ->orderBy('code')
                ->get(),
            'rosterPatterns' => AttendanceRosterPattern::query()
                ->where('company_id', $companyId)
                ->orderBy('code')
                ->get(),
            'rosterAssignments' => AttendanceRosterAssignment::query()
                ->where('company_id', $companyId)
                ->with(['employee', 'shiftTemplate', 'policyGroup', 'rosterPattern'])
                ->latest('effective_from')
                ->limit(40)
                ->get(),
            'geofences' => AttendanceGeofence::query()
                ->where('company_id', $companyId)
                ->orderBy('code')
                ->get(),
            'geofenceGroups' => AttendanceGeofenceGroup::query()
                ->where('company_id', $companyId)
                ->with('fences')
                ->orderBy('code')
                ->get(),
            'statusOptions' => $this->statusOptions(),
        ]);
    }

    public function statusVariant(string $status): string
    {
        return match ($status) {
            AttendanceDay::STATUS_READY_FOR_REVIEW, AttendanceDay::STATUS_FINALIZED, AttendanceDay::STATUS_EXPORTED_TO_PAYROLL => 'success',
            AttendanceDay::STATUS_EXCEPTION_PENDING, AttendanceDay::STATUS_IN_PROGRESS => 'warning',
            AttendanceDay::STATUS_LOCKED => 'danger',
            default => 'info',
        };
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

    private function companyId(): int
    {
        return auth()->user()?->company_id ?? Company::LICENSEE_ID;
    }

    private function currentEmployeeId(): ?int
    {
        $id = auth()->user()?->employee_id;

        return $id === null ? null : (int) $id;
    }
}
