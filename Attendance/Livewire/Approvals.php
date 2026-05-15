<?php

namespace App\Modules\People\Attendance\Livewire;

use App\Modules\People\Attendance\Livewire\Concerns\InteractsWithAttendanceScreen;
use App\Modules\People\Attendance\Models\AttendanceOvertimeRequest;
use App\Modules\People\Attendance\Services\AttendanceOvertimeService;
use Illuminate\Contracts\View\View;
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

    public function render(): View
    {
        $companyId = $this->companyId();
        $schemaReady = $this->schemaReady();

        return view('livewire.people.attendance.approvals', [
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
        ]);
    }

    private function overtimeRequest(int $requestId): AttendanceOvertimeRequest
    {
        return AttendanceOvertimeRequest::query()
            ->where('company_id', $this->companyId())
            ->findOrFail($requestId);
    }
}
