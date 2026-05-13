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
use App\Modules\People\Attendance\Services\AttendanceDayResolverService;
use App\Modules\People\Attendance\Services\AttendanceLifecycleService;
use App\Modules\People\Attendance\Services\AttendanceOvertimeService;
use App\Modules\People\Attendance\Services\ClockEventIngestionService;
use DateTimeImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;
use Livewire\Component;

class Index extends Component
{
    public string $surface = 'my';

    public string $search = '';

    public string $status = '';

    public bool $showOvertimeModal = false;

    public string $overtimeDate = '';

    public string $overtimeStartsAt = '';

    public string $overtimeEndsAt = '';

    public string $overtimeRequestedHours = '1.00';

    public string $overtimeReason = '';

    public string $decisionReason = '';

    public function mount(?string $surface = null): void
    {
        $this->surface = in_array($surface, ['my', 'approvals', 'operations', 'settings'], true) ? $surface : 'my';
        $this->overtimeDate = now()->toDateString();
        $this->overtimeStartsAt = now()->setTime(17, 0)->format('H:i');
        $this->overtimeEndsAt = now()->setTime(18, 0)->format('H:i');
    }

    public function updatedSearch(): void
    {
        $this->search = trim($this->search);
    }

    public function clock(string $eventType): void
    {
        if (! $this->ensureSchemaReady()) {
            return;
        }

        if (! in_array($eventType, [AttendanceClockEvent::TYPE_IN, AttendanceClockEvent::TYPE_OUT], true)) {
            return;
        }

        app(AuthorizationService::class)->authorize(
            Actor::forUser(Auth::user()),
            'people.attendance.execute',
        );

        $employeeId = $this->currentEmployeeId();
        if ($employeeId === null) {
            session()->flash('error', __('Your user account is not linked to an employee record.'));

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

        session()->flash('success', __('Clock event recorded.'));
    }

    public function openOvertimeModal(): void
    {
        if (! $this->ensureSchemaReady()) {
            return;
        }

        $this->resetValidation();
        $this->showOvertimeModal = true;
    }

    public function submitOvertimeRequest(): void
    {
        if (! $this->ensureSchemaReady()) {
            return;
        }

        app(AuthorizationService::class)->authorize(
            Actor::forUser(Auth::user()),
            'people.attendance.execute',
        );

        $employeeId = $this->currentEmployeeId();
        if ($employeeId === null) {
            session()->flash('error', __('Your user account is not linked to an employee record.'));

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
        session()->flash('success', __('Overtime request submitted.'));
    }

    public function approveOvertime(int $requestId): void
    {
        if (! $this->ensureSchemaReady()) {
            return;
        }

        $this->authorizeAttendance('people.attendance.approve');

        $request = $this->overtimeRequest($requestId);
        app(AttendanceOvertimeService::class)->approve($request, decisionReason: $this->blankToNull($this->decisionReason));
        $this->decisionReason = '';

        session()->flash('success', __('Overtime request approved.'));
    }

    public function rejectOvertime(int $requestId): void
    {
        if (! $this->ensureSchemaReady()) {
            return;
        }

        $this->authorizeAttendance('people.attendance.approve');

        $request = $this->overtimeRequest($requestId);
        app(AttendanceOvertimeService::class)->reject($request, $this->blankToNull($this->decisionReason));
        $this->decisionReason = '';

        session()->flash('success', __('Overtime request rejected.'));
    }

    public function queueOvertimePayroll(int $requestId): void
    {
        if (! $this->ensureSchemaReady()) {
            return;
        }

        $this->authorizeAttendance('people.attendance.approve');

        $request = $this->overtimeRequest($requestId);
        $outcome = app(AttendanceOvertimeService::class)->queuePayrollHandoff($request);

        if ($outcome === null) {
            session()->flash('error', __('No payable minutes on this overtime request.'));

            return;
        }

        $messageKey = $outcome->isMaterialized() ? 'success' : 'error';
        $message = match (true) {
            $outcome->isMaterialized() => __('Overtime queued to payroll.'),
            $outcome->isPending() => __('Saved as pending — no open payroll run covers this overtime date.'),
            $outcome->isRejected() => __('Cannot queue: the payroll run for this period is closed.'),
            default => __('Overtime contribution recorded.'),
        };

        session()->flash($messageKey, $message);
    }

    public function finalizeDay(int $dayId): void
    {
        if (! $this->ensureSchemaReady()) {
            return;
        }

        $this->authorizeAttendance('people.attendance.manage');

        app(AttendanceLifecycleService::class)->finalize($this->attendanceDay($dayId));

        session()->flash('success', __('Attendance day finalized.'));
    }

    public function lockDay(int $dayId): void
    {
        if (! $this->ensureSchemaReady()) {
            return;
        }

        $this->authorizeAttendance('people.attendance.manage');

        app(AttendanceLifecycleService::class)->lock($this->attendanceDay($dayId));

        session()->flash('success', __('Attendance day locked.'));
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
        $canClock = $authz->can($actor, 'people.attendance.execute')->allowed;
        $schemaReady = Schema::hasTable('people_attendance_days');

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

        if (! $schemaReady) {
            return view('livewire.people.attendance.index', $this->emptyViewData(
                surface: $this->surface,
                surfaceTitle: $surfaceTitle,
                surfaceSubtitle: $surfaceSubtitle,
                canManage: $canManage,
                canApprove: $canApprove,
                canClock: $canClock,
                currentEmployeeId: $currentEmployeeId,
            ));
        }

        return view('livewire.people.attendance.index', [
            'surface' => $this->surface,
            'surfaceTitle' => $surfaceTitle,
            'surfaceSubtitle' => $surfaceSubtitle,
            'schemaReady' => true,
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
            'overtimeRequests' => AttendanceOvertimeRequest::query()
                ->where('company_id', $companyId)
                ->whereIn('status', [
                    AttendanceOvertimeRequest::STATUS_SUBMITTED,
                    AttendanceOvertimeRequest::STATUS_APPROVED,
                    AttendanceOvertimeRequest::STATUS_QUEUED_FOR_PAYROLL,
                ])
                ->with(['employee', 'attendanceDay'])
                ->latest('submitted_at')
                ->limit(60)
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

    private function authorizeAttendance(string $capability): void
    {
        app(AuthorizationService::class)->authorize(
            Actor::forUser(Auth::user()),
            $capability,
        );
    }

    private function attendanceDay(int $dayId): AttendanceDay
    {
        return AttendanceDay::query()
            ->where('company_id', $this->companyId())
            ->findOrFail($dayId);
    }

    private function overtimeRequest(int $requestId): AttendanceOvertimeRequest
    {
        return AttendanceOvertimeRequest::query()
            ->where('company_id', $this->companyId())
            ->findOrFail($requestId);
    }

    private function blankToNull(mixed $value): mixed
    {
        if (is_string($value) && trim($value) === '') {
            return null;
        }

        return $value;
    }

    private function ensureSchemaReady(): bool
    {
        if (Schema::hasTable('people_attendance_days')) {
            return true;
        }

        session()->flash('error', __('Attendance database tables are not installed yet. Run the Attendance migration first.'));

        return false;
    }

    /**
     * @return array<string, mixed>
     */
    private function emptyViewData(
        string $surface,
        string $surfaceTitle,
        string $surfaceSubtitle,
        bool $canManage,
        bool $canApprove,
        bool $canClock,
        ?int $currentEmployeeId,
    ): array {
        /** @var Collection<int, mixed> $empty */
        $empty = collect();

        return [
            'surface' => $surface,
            'surfaceTitle' => $surfaceTitle,
            'surfaceSubtitle' => $surfaceSubtitle,
            'schemaReady' => false,
            'canManage' => $canManage,
            'canApprove' => $canApprove,
            'canClock' => $canClock,
            'currentEmployeeId' => $currentEmployeeId,
            'attendanceDays' => $empty,
            'pendingOvertime' => $empty,
            'overtimeRequests' => $empty,
            'clockEvents' => $empty,
            'absenceBatches' => $empty,
            'shiftTemplates' => $empty,
            'policyGroups' => $empty,
            'allowanceRules' => $empty,
            'rosterPatterns' => $empty,
            'rosterAssignments' => $empty,
            'geofences' => $empty,
            'geofenceGroups' => $empty,
            'statusOptions' => $this->statusOptions(),
        ];
    }
}
