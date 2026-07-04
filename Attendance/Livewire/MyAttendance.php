<?php

namespace App\Modules\People\Attendance\Livewire;

use App\Base\Foundation\Livewire\Concerns\InteractsWithNotifications;
use App\Modules\Core\Employee\Models\Employee;
use App\Modules\People\Attendance\Livewire\Concerns\InteractsWithAttendanceScreen;
use App\Modules\People\Attendance\Models\AttendanceAdjustmentRequest;
use App\Modules\People\Attendance\Models\AttendanceClockEvent;
use App\Modules\People\Attendance\Models\AttendanceDay;
use App\Modules\People\Attendance\Models\AttendanceOvertimeRequest;
use App\Modules\People\Attendance\Services\AttendanceAdjustmentService;
use App\Modules\People\Attendance\Services\AttendanceDayResolverService;
use App\Modules\People\Attendance\Services\AttendanceOvertimeService;
use App\Modules\People\Attendance\Services\ClockEventIngestionService;
use DateTimeImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;
use Throwable;

class MyAttendance extends Component
{
    use InteractsWithAttendanceScreen;
    use InteractsWithNotifications;

    public string $search = '';

    public string $status = '';

    public bool $showOvertimeModal = false;

    public string $overtimeDate = '';

    public string $overtimeStartsAt = '';

    public string $overtimeEndsAt = '';

    public string $overtimeRequestedHours = '1.00';

    /**
     * Tracks whether the user has manually edited Requested Hours. While
     * false, changes to start/end time auto-fill the field from the derived
     * duration (rounded to the input's 0.25h step). Once the user edits it,
     * we stop overriding their intent.
     */
    public bool $overtimeRequestedHoursTouched = false;

    public string $overtimeReason = '';

    public bool $showAdjustmentModal = false;

    public string $adjustmentDate = '';

    public string $adjustmentTime = '';

    public string $adjustmentEventType = AttendanceClockEvent::TYPE_IN;

    public string $adjustmentReason = '';

    public function mount(): void
    {
        $this->overtimeDate = now()->toDateString();
        $this->overtimeStartsAt = now()->setTime(17, 0)->format('H:i');
        $this->overtimeEndsAt = now()->setTime(18, 0)->format('H:i');
        $this->adjustmentDate = now()->toDateString();
        $this->adjustmentTime = now()->format('H:i');
    }

    public function updatedSearch(): void
    {
        $this->search = trim($this->search);
    }

    public function updatedOvertimeStartsAt(): void
    {
        $this->recomputeRequestedHours();
    }

    public function updatedOvertimeEndsAt(): void
    {
        $this->recomputeRequestedHours();
    }

    public function updatedOvertimeDate(): void
    {
        $this->recomputeRequestedHours();
    }

    public function updatedOvertimeRequestedHours(): void
    {
        $this->overtimeRequestedHoursTouched = true;
    }

    /**
     * Auto-fill Requested Hours from the start/end duration while the user
     * has not manually edited it. Mirrors the midnight-rollover rule used at
     * submission so the previewed value matches what will be persisted, and
     * rounds to the 0.25h step declared on the input.
     */
    private function recomputeRequestedHours(): void
    {
        if ($this->overtimeRequestedHoursTouched) {
            return;
        }

        if ($this->overtimeDate === '' || $this->overtimeStartsAt === '' || $this->overtimeEndsAt === '') {
            return;
        }

        try {
            $startsAt = new DateTimeImmutable($this->overtimeDate.' '.$this->overtimeStartsAt);
            $endsAt = new DateTimeImmutable($this->overtimeDate.' '.$this->overtimeEndsAt);
        } catch (Throwable) {
            return;
        }

        if ($endsAt <= $startsAt) {
            $endsAt = $endsAt->modify('+1 day');
        }

        $minutes = (int) round(($endsAt->getTimestamp() - $startsAt->getTimestamp()) / 60);
        if ($minutes <= 0) {
            return;
        }

        // Round to the nearest quarter-hour to honor the input's step=0.25.
        $hours = round($minutes / 60 * 4) / 4;
        $this->overtimeRequestedHours = number_format($hours, 2, '.', '');
    }

    public function clock(string $eventType): void
    {
        if (! $this->ensureSchemaReady()) {
            return;
        }

        if (! in_array($eventType, [AttendanceClockEvent::TYPE_IN, AttendanceClockEvent::TYPE_OUT], true)) {
            return;
        }

        $this->authorizeAttendance('people.attendance.execute');

        $employeeId = $this->currentEmployeeId();
        if ($employeeId === null) {
            $this->notifyError(__('Your user account is not linked to an employee record.'));

            return;
        }

        $employee = Employee::query()
            ->where('company_id', $this->companyId())
            ->findOrFail($employeeId);

        app(ClockEventIngestionService::class)->recordWebClock(
            employee: $employee,
            eventType: $eventType,
            actorUserId: (int) Auth::id(),
            ipAddress: request()->ip(),
            timezone: config('app.timezone'),
        );

        $this->notify(__('Clock event recorded.'));
    }

    public function openOvertimeModal(): void
    {
        if (! $this->ensureSchemaReady()) {
            return;
        }

        $this->resetValidation();
        $this->overtimeRequestedHoursTouched = false;
        $this->recomputeRequestedHours();
        $this->showOvertimeModal = true;
    }

    public function submitOvertimeRequest(): void
    {
        if (! $this->ensureSchemaReady()) {
            return;
        }

        $this->authorizeAttendance('people.attendance.execute');

        $employeeId = $this->currentEmployeeId();
        if ($employeeId === null) {
            $this->notifyError(__('Your user account is not linked to an employee record.'));

            return;
        }

        $validated = $this->validate([
            'overtimeDate' => ['required', 'date'],
            'overtimeStartsAt' => ['required', 'date_format:H:i'],
            'overtimeEndsAt' => ['required', 'date_format:H:i'],
            'overtimeRequestedHours' => ['required', 'numeric', 'min:0.25', 'max:24'],
            'overtimeReason' => ['nullable', 'string', 'max:500'],
        ]);

        $employee = Employee::query()
            ->where('company_id', $this->companyId())
            ->findOrFail($employeeId);
        $day = app(AttendanceDayResolverService::class)->resolve($employee, $validated['overtimeDate']);
        $startsAt = new DateTimeImmutable($validated['overtimeDate'].' '.$validated['overtimeStartsAt']);
        $endsAt = new DateTimeImmutable($validated['overtimeDate'].' '.$validated['overtimeEndsAt']);
        if ($endsAt <= $startsAt) {
            $endsAt = $endsAt->modify('+1 day');
        }

        $request = AttendanceOvertimeRequest::query()->create([
            'company_id' => $employee->company_id,
            'employee_id' => $employee->id,
            'attendance_day_id' => $day->id,
            'request_mode' => 'post_work_actual',
            'status' => AttendanceOvertimeRequest::STATUS_DRAFT,
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'requested_minutes' => (int) round(((float) $validated['overtimeRequestedHours']) * 60),
            'reason' => $this->blankToNull($validated['overtimeReason'] ?? null),
            'submitted_by_user_id' => Auth::id(),
        ]);

        app(AttendanceOvertimeService::class)->submit($request, (int) Auth::id());

        $this->showOvertimeModal = false;
        $this->overtimeReason = '';
        $this->notify(__('Overtime request submitted.'));
    }

    public function openAdjustmentModal(): void
    {
        if (! $this->ensureSchemaReady()) {
            return;
        }

        $this->resetValidation();
        $this->showAdjustmentModal = true;
    }

    public function submitAdjustmentRequest(): void
    {
        if (! $this->ensureSchemaReady()) {
            return;
        }

        $this->authorizeAttendance('people.attendance.execute');

        $employeeId = $this->currentEmployeeId();
        if ($employeeId === null) {
            $this->notifyError(__('Your user account is not linked to an employee record.'));

            return;
        }

        $validated = $this->validate([
            'adjustmentDate' => ['required', 'date'],
            'adjustmentTime' => ['required', 'date_format:H:i'],
            'adjustmentEventType' => ['required', 'in:'.AttendanceClockEvent::TYPE_IN.','.AttendanceClockEvent::TYPE_OUT.','.AttendanceClockEvent::TYPE_BREAK_OUT.','.AttendanceClockEvent::TYPE_BREAK_IN],
            'adjustmentReason' => ['required', 'string', 'max:500'],
        ]);

        $employee = Employee::query()
            ->where('company_id', $this->companyId())
            ->findOrFail($employeeId);
        $day = app(AttendanceDayResolverService::class)->resolve($employee, $validated['adjustmentDate']);

        $request = AttendanceAdjustmentRequest::query()->create([
            'company_id' => $employee->company_id,
            'employee_id' => $employee->id,
            'attendance_day_id' => $day->id,
            'request_mode' => AttendanceAdjustmentRequest::MODE_MISSING_PUNCH,
            'target_event_type' => $validated['adjustmentEventType'],
            'proposed_occurred_at' => new DateTimeImmutable($validated['adjustmentDate'].' '.$validated['adjustmentTime']),
            'reason' => $validated['adjustmentReason'],
            'status' => AttendanceAdjustmentRequest::STATUS_DRAFT,
        ]);

        app(AttendanceAdjustmentService::class)->submit($request, (int) Auth::id());

        $this->showAdjustmentModal = false;
        $this->adjustmentReason = '';
        $this->notify(__('Adjustment request submitted.'));
    }

    public function render(): View
    {
        $companyId = $this->companyId();
        $currentEmployeeId = $this->currentEmployeeId();
        $schemaReady = $this->schemaReady();
        $canClock = $this->canAttendance('people.attendance.execute');
        $search = trim($this->search);

        return view('people-attendance::livewire.people.attendance.my-attendance', [
            'schemaReady' => $schemaReady,
            'canClock' => $canClock,
            'canManage' => false,
            'surface' => 'my',
            'currentEmployeeId' => $currentEmployeeId,
            'statusOptions' => $this->statusOptions(),
            'attendanceDays' => $schemaReady
                ? AttendanceDay::query()
                    ->where('company_id', $companyId)
                    ->with(['employee', 'shiftTemplate', 'policyGroup'])
                    ->when($currentEmployeeId !== null, fn ($query) => $query->where('employee_id', $currentEmployeeId))
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
            'myAdjustments' => $schemaReady && $currentEmployeeId !== null
                ? AttendanceAdjustmentRequest::query()
                    ->where('company_id', $companyId)
                    ->where('employee_id', $currentEmployeeId)
                    ->latest('id')
                    ->limit(10)
                    ->get()
                : collect(),
            'eventTypeOptions' => [
                AttendanceClockEvent::TYPE_IN => __('Clock-in'),
                AttendanceClockEvent::TYPE_OUT => __('Clock-out'),
                AttendanceClockEvent::TYPE_BREAK_OUT => __('Break out'),
                AttendanceClockEvent::TYPE_BREAK_IN => __('Break in'),
            ],
            'clockEvents' => $schemaReady
                ? AttendanceClockEvent::query()
                    ->where('company_id', $companyId)
                    ->when($currentEmployeeId !== null, fn ($query) => $query->where('employee_id', $currentEmployeeId))
                    ->latest('occurred_at')
                    ->limit(40)
                    ->get()
                : collect(),
            'policyGroups' => collect(),
        ]);
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
