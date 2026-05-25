<?php

namespace App\Modules\People\Attendance\Livewire;

use App\Modules\People\Attendance\Livewire\Concerns\InteractsWithAttendanceScreen;
use App\Modules\People\Attendance\Models\AttendanceAdjustmentRequest;
use App\Modules\People\Attendance\Models\AttendanceOvertimeRequest;
use App\Modules\People\Attendance\Services\AttendanceAdjustmentService;
use App\Modules\People\Attendance\Services\AttendanceOvertimeService;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

class Approvals extends Component
{
    use InteractsWithAttendanceScreen;

    public string $decisionReason = '';

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
        $dispatched = app(AttendanceOvertimeService::class)->queuePayrollHandoff($request);

        if (! $dispatched) {
            session()->flash('error', __('No payable minutes on this overtime request.'));

            return;
        }

        session()->flash('success', __('Overtime queued to payroll. Check the Payroll module for the contribution status.'));
    }

    public function approveAdjustment(int $requestId): void
    {
        if (! $this->ensureSchemaReady()) {
            return;
        }

        $this->authorizeAttendance('people.attendance.approve');

        $request = $this->adjustmentRequest($requestId);
        app(AttendanceAdjustmentService::class)->approve($request, (int) Auth::id(), $this->blankToNull($this->decisionReason));
        $this->decisionReason = '';

        session()->flash('success', __('Adjustment request approved; clock event recorded.'));
    }

    public function rejectAdjustment(int $requestId): void
    {
        if (! $this->ensureSchemaReady()) {
            return;
        }

        $this->authorizeAttendance('people.attendance.approve');

        $request = $this->adjustmentRequest($requestId);
        app(AttendanceAdjustmentService::class)->reject($request, (int) Auth::id(), $this->blankToNull($this->decisionReason));
        $this->decisionReason = '';

        session()->flash('success', __('Adjustment request rejected.'));
    }

    public function render(): View
    {
        $companyId = $this->companyId();
        $schemaReady = $this->schemaReady();

        return view('people-attendance::livewire.people.attendance.approvals', [
            'schemaReady' => $schemaReady,
            'canApprove' => $this->canAttendance('people.attendance.approve'),
            'overtimeRequests' => $schemaReady
                ? AttendanceOvertimeRequest::query()
                    ->where('company_id', $companyId)
                    ->whereIn('status', [
                        AttendanceOvertimeRequest::STATUS_SUBMITTED,
                        AttendanceOvertimeRequest::STATUS_APPROVED,
                        AttendanceOvertimeRequest::STATUS_QUEUED_FOR_PAYROLL,
                    ])
                    ->with(['employee', 'attendanceDay'])
                    ->latest('submitted_at')
                    ->limit(60)
                    ->get()
                : collect(),
            'adjustmentRequests' => $schemaReady
                ? AttendanceAdjustmentRequest::query()
                    ->where('company_id', $companyId)
                    ->whereIn('status', [
                        AttendanceAdjustmentRequest::STATUS_SUBMITTED,
                        AttendanceAdjustmentRequest::STATUS_APPROVED,
                    ])
                    ->with(['employee', 'attendanceDay'])
                    ->latest('submitted_at')
                    ->limit(60)
                    ->get()
                : collect(),
        ]);
    }

    private function overtimeRequest(int $requestId): AttendanceOvertimeRequest
    {
        return AttendanceOvertimeRequest::query()
            ->where('company_id', $this->companyId())
            ->findOrFail($requestId);
    }

    private function adjustmentRequest(int $requestId): AttendanceAdjustmentRequest
    {
        return AttendanceAdjustmentRequest::query()
            ->where('company_id', $this->companyId())
            ->findOrFail($requestId);
    }
}
