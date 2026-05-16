<?php

namespace App\Modules\People\Payroll\Listeners;

use App\Modules\People\Attendance\Events\AttendanceOvertimeApproved;
use App\Modules\People\Attendance\Exceptions\AttendanceOvertimeException;
use App\Modules\People\Attendance\Models\AttendanceOvertimeRequest;
use App\Modules\People\Payroll\Contracts\Intake\PayrollContributionPayload;
use App\Modules\People\Payroll\Services\PayrollContributionIntake;

/**
 * Translates an attendance overtime approval into a payroll contribution.
 *
 * Reads the OT request (and its policy context) to derive the pay-item
 * code — that resolution is a payroll concept and lives here, not in
 * Attendance. The intake handles idempotency, run-locking, and pending
 * materialisation.
 *
 * The OT pay-item code is read from `policy_snapshot` (a snapshot of the
 * policy rules captured at OT submission time, owned by Attendance) and
 * falls back to a hardcoded default. A per-policy-group OT mapping table
 * (mirror of the allowance mapping) is a future enhancement when the
 * fallback proves too coarse.
 */
class RecordAttendanceOvertimeContribution
{
    public const SOURCE_TYPE = 'attendance_overtime_request';

    public function __construct(
        private readonly PayrollContributionIntake $intake,
    ) {}

    public function handle(AttendanceOvertimeApproved $event): void
    {
        if ($event->payableMinutes <= 0) {
            return;
        }

        $request = AttendanceOvertimeRequest::query()->find($event->overtimeRequestId);
        if ($request === null) {
            return;
        }

        $payload = $this->buildPayload($event, $request);
        $this->intake->ingest($payload);
    }

    private function buildPayload(AttendanceOvertimeApproved $event, AttendanceOvertimeRequest $request): PayrollContributionPayload
    {
        $quantity = round($event->payableMinutes / 60, 4);

        return new PayrollContributionPayload(
            sourceType: self::SOURCE_TYPE,
            sourceId: $event->overtimeRequestId,
            payItemCode: $this->resolvePayItemCode($request),
            periodAnchor: $event->occurredOn,
            companyId: $event->companyId,
            employeeId: $event->employeeId,
            currency: 'MYR',
            occurredOn: $event->occurredOn,
            inputType: 'earning',
            amount: 0.0,
            quantity: $quantity,
            rate: null,
            label: 'Attendance overtime',
            accountingSnapshot: [],
            metadata: [
                'attendance_day_id' => $event->attendanceDayId,
                'approved_minutes' => $request->approved_minutes,
                'payable_minutes' => $event->payableMinutes,
                'request_mode' => $request->request_mode,
                'hours' => $quantity,
            ],
        );
    }

    private function resolvePayItemCode(AttendanceOvertimeRequest $request): string
    {
        $snapshot = $request->policy_snapshot ?? [];

        $payItemCode = $snapshot['pay_item_code']
            ?? $snapshot['overtime_pay_item_code']
            ?? 'ATT_OT';

        if (! is_string($payItemCode) || $payItemCode === '') {
            throw AttendanceOvertimeException::missingPayItem($request->id);
        }

        return $payItemCode;
    }
}
