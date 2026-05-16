<?php

namespace App\Modules\People\Attendance\Services;

use App\Modules\People\Attendance\Events\AttendanceOvertimeApproved;
use App\Modules\People\Attendance\Exceptions\AttendanceOvertimeException;
use App\Modules\People\Attendance\Models\AttendanceOvertimeRequest;
use DateTimeImmutable;

class AttendanceOvertimeService
{
    public const SOURCE_TYPE = 'attendance_overtime_request';

    public function submit(AttendanceOvertimeRequest $request, int $actorUserId): AttendanceOvertimeRequest
    {
        if ($request->status !== AttendanceOvertimeRequest::STATUS_DRAFT) {
            throw AttendanceOvertimeException::invalidTransition($request->id, $request->status, AttendanceOvertimeRequest::STATUS_SUBMITTED);
        }

        $request->forceFill([
            'status' => AttendanceOvertimeRequest::STATUS_SUBMITTED,
            'submitted_by_user_id' => $actorUserId,
            'submitted_at' => now(),
        ])->save();

        return $request;
    }

    public function approve(AttendanceOvertimeRequest $request, ?int $approvedMinutes = null, ?string $decisionReason = null): AttendanceOvertimeRequest
    {
        if (! in_array($request->status, [AttendanceOvertimeRequest::STATUS_DRAFT, AttendanceOvertimeRequest::STATUS_SUBMITTED], true)) {
            throw AttendanceOvertimeException::invalidTransition($request->id, $request->status, AttendanceOvertimeRequest::STATUS_APPROVED);
        }

        $approved = $approvedMinutes ?? $request->requested_minutes;

        $request->forceFill([
            'status' => AttendanceOvertimeRequest::STATUS_APPROVED,
            'approved_minutes' => $approved,
            'payable_minutes' => $approved,
            'approved_at' => now(),
            'decision_reason' => $decisionReason,
        ])->save();

        return $request;
    }

    public function reject(AttendanceOvertimeRequest $request, ?string $decisionReason = null): AttendanceOvertimeRequest
    {
        if (! in_array($request->status, [AttendanceOvertimeRequest::STATUS_DRAFT, AttendanceOvertimeRequest::STATUS_SUBMITTED], true)) {
            throw AttendanceOvertimeException::invalidTransition($request->id, $request->status, AttendanceOvertimeRequest::STATUS_REJECTED);
        }

        $request->forceFill([
            'status' => AttendanceOvertimeRequest::STATUS_REJECTED,
            'rejected_at' => now(),
            'decision_reason' => $decisionReason,
        ])->save();

        return $request;
    }

    /**
     * Dispatch an AttendanceOvertimeApproved event so downstream consumers
     * (the Payroll plugin, audit sinks) can record the contribution.
     *
     * Returns true when an event was dispatched, false when the request had
     * nothing payable. The producer no longer learns whether the listener
     * materialised a PayrollInput, persisted a pending contribution, or
     * rejected — that is a downstream-status query, not a producer concern.
     */
    public function queuePayrollHandoff(AttendanceOvertimeRequest $request): bool
    {
        if ($request->status !== AttendanceOvertimeRequest::STATUS_APPROVED && $request->status !== AttendanceOvertimeRequest::STATUS_QUEUED_FOR_PAYROLL) {
            throw AttendanceOvertimeException::invalidTransition($request->id, $request->status, AttendanceOvertimeRequest::STATUS_QUEUED_FOR_PAYROLL);
        }

        if ($request->payable_minutes <= 0) {
            return false;
        }

        if ($request->status !== AttendanceOvertimeRequest::STATUS_QUEUED_FOR_PAYROLL) {
            $request->forceFill([
                'status' => AttendanceOvertimeRequest::STATUS_QUEUED_FOR_PAYROLL,
                'queued_for_payroll_at' => now(),
            ])->save();
        }

        event(new AttendanceOvertimeApproved(
            companyId: (int) $request->company_id,
            employeeId: (int) $request->employee_id,
            overtimeRequestId: (int) $request->id,
            occurredOn: $this->occurredOn($request),
            payableMinutes: (int) $request->payable_minutes,
            attendanceDayId: $request->attendance_day_id !== null ? (int) $request->attendance_day_id : null,
        ));

        return true;
    }

    private function occurredOn(AttendanceOvertimeRequest $request): DateTimeImmutable
    {
        $date = $request->starts_at?->toDateString()
            ?? $request->attendanceDay?->attendance_date?->toDateString()
            ?? now()->toDateString();

        return new DateTimeImmutable($date);
    }
}
