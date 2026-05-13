<?php

namespace App\Modules\People\Attendance\Services;

use App\Modules\People\Attendance\Exceptions\AttendanceOvertimeException;
use App\Modules\People\Attendance\Models\AttendanceOvertimeRequest;
use App\Modules\People\Payroll\Contracts\Intake\PayrollContributionOutcome;
use App\Modules\People\Payroll\Contracts\Intake\PayrollContributionPayload;
use App\Modules\People\Payroll\Services\PayrollContributionIntake;
use DateTimeImmutable;

class AttendanceOvertimeService
{
    public const SOURCE_TYPE = 'attendance_overtime_request';

    public function __construct(
        private readonly PayrollContributionIntake $intake,
    ) {}

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
     * Hand the approved overtime to Payroll via the intake contract.
     *
     * Returns an outcome the caller can branch on:
     *  - state=queued_in_run: a PayrollInput row was created in an open run.
     *  - state=pending: no open run covers the period; intake will materialise
     *    it when a run opens.
     *  - state=rejected_locked: the only matching run is closed/voided.
     */
    public function queuePayrollHandoff(AttendanceOvertimeRequest $request): ?PayrollContributionOutcome
    {
        if ($request->status !== AttendanceOvertimeRequest::STATUS_APPROVED && $request->status !== AttendanceOvertimeRequest::STATUS_QUEUED_FOR_PAYROLL) {
            throw AttendanceOvertimeException::invalidTransition($request->id, $request->status, AttendanceOvertimeRequest::STATUS_QUEUED_FOR_PAYROLL);
        }

        if ($request->payable_minutes <= 0) {
            return null;
        }

        $payload = $this->buildPayload($request);
        $outcome = $this->intake->ingest($payload);

        if ($outcome->isMaterialized() && $request->status !== AttendanceOvertimeRequest::STATUS_QUEUED_FOR_PAYROLL) {
            $request->forceFill([
                'status' => AttendanceOvertimeRequest::STATUS_QUEUED_FOR_PAYROLL,
                'queued_for_payroll_at' => now(),
            ])->save();
        }

        return $outcome;
    }

    private function buildPayload(AttendanceOvertimeRequest $request): PayrollContributionPayload
    {
        $occurredOn = $this->occurredOn($request);
        $quantity = round($request->payable_minutes / 60, 4);

        return new PayrollContributionPayload(
            sourceType: self::SOURCE_TYPE,
            sourceId: (int) $request->id,
            payItemCode: $this->payItemCode($request),
            periodAnchor: $occurredOn,
            companyId: (int) $request->company_id,
            employeeId: (int) $request->employee_id,
            currency: 'MYR',
            occurredOn: $occurredOn,
            inputType: 'earning',
            amount: 0.0,
            quantity: $quantity,
            rate: null,
            label: 'Attendance overtime',
            accountingSnapshot: [],
            metadata: [
                'attendance_day_id' => $request->attendance_day_id,
                'approved_minutes' => $request->approved_minutes,
                'payable_minutes' => $request->payable_minutes,
                'request_mode' => $request->request_mode,
                'hours' => $quantity,
            ],
        );
    }

    private function occurredOn(AttendanceOvertimeRequest $request): DateTimeImmutable
    {
        $date = $request->starts_at?->toDateString()
            ?? $request->attendanceDay?->attendance_date?->toDateString()
            ?? now()->toDateString();

        return new DateTimeImmutable($date);
    }

    private function payItemCode(AttendanceOvertimeRequest $request): string
    {
        $request->loadMissing(['attendanceDay.policyGroup']);

        $snapshot = $request->policy_snapshot ?? [];
        $payrollDefaults = $request->attendanceDay?->policyGroup?->payroll_defaults ?? [];

        $payItemCode = $snapshot['pay_item_code']
            ?? $snapshot['overtime_pay_item_code']
            ?? $payrollDefaults['overtime_pay_item_code']
            ?? 'ATT_OT';

        if (! is_string($payItemCode) || $payItemCode === '') {
            throw AttendanceOvertimeException::missingPayItem($request->id);
        }

        return $payItemCode;
    }
}
