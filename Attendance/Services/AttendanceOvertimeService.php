<?php

namespace App\Modules\People\Attendance\Services;

use App\Modules\People\Attendance\Exceptions\AttendanceOvertimeException;
use App\Modules\People\Attendance\Models\AttendanceOvertimeRequest;
use App\Modules\People\Attendance\Models\AttendancePayrollHandoff;
use App\Modules\People\Payroll\Models\PayrollInput;
use App\Modules\People\Payroll\Models\PayrollRun;
use App\Modules\People\Payroll\Models\PayrollRunParticipant;
use Illuminate\Support\Facades\DB;

class AttendanceOvertimeService
{
    private const OPEN_RUN_STATUSES = [
        PayrollRun::STATUS_DRAFT,
        PayrollRun::STATUS_CALCULATED,
    ];

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

    public function queuePayrollHandoff(AttendanceOvertimeRequest $request): ?AttendancePayrollHandoff
    {
        $existing = AttendancePayrollHandoff::query()
            ->where('source_type', AttendanceOvertimeRequest::class)
            ->where('source_id', $request->id)
            ->where('pay_item_code', $this->payItemCode($request))
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        if ($request->status !== AttendanceOvertimeRequest::STATUS_APPROVED) {
            throw AttendanceOvertimeException::invalidTransition($request->id, $request->status, AttendanceOvertimeRequest::STATUS_QUEUED_FOR_PAYROLL);
        }

        if ($request->payable_minutes <= 0) {
            return null;
        }

        return DB::transaction(function () use ($request): ?AttendancePayrollHandoff {
            $run = $this->findOpenRunFor($request);
            if ($run === null) {
                return null;
            }

            $participant = $this->ensureParticipant($run, (int) $request->employee_id);
            $payItemCode = $this->payItemCode($request);
            $quantity = round($request->payable_minutes / 60, 4);
            $occurredOn = $request->starts_at?->toDateString()
                ?? $request->attendanceDay?->attendance_date?->toDateString()
                ?? now()->toDateString();

            $input = PayrollInput::query()->create([
                'payroll_run_id' => $run->id,
                'payroll_run_participant_id' => $participant->id,
                'employee_id' => $request->employee_id,
                'source_type' => 'attendance_overtime_request',
                'source_id' => $request->id,
                'pay_item_code' => $payItemCode,
                'label' => 'Attendance overtime',
                'input_type' => PayrollInput::TYPE_EARNING,
                'quantity' => $quantity,
                'rate' => null,
                'amount' => 0,
                'currency' => $run->currency,
                'occurred_on' => $occurredOn,
                'metadata' => [
                    'attendance_day_id' => $request->attendance_day_id,
                    'approved_minutes' => $request->approved_minutes,
                    'payable_minutes' => $request->payable_minutes,
                    'request_mode' => $request->request_mode,
                ],
            ]);

            $handoff = AttendancePayrollHandoff::query()->create([
                'company_id' => $request->company_id,
                'employee_id' => $request->employee_id,
                'source_type' => AttendanceOvertimeRequest::class,
                'source_id' => $request->id,
                'payroll_input_id' => $input->id,
                'pay_item_code' => $payItemCode,
                'input_type' => PayrollInput::TYPE_EARNING,
                'quantity' => $quantity,
                'amount' => 0,
                'occurred_on' => $occurredOn,
                'payroll_period_date' => $run->period?->starts_on,
                'status' => AttendancePayrollHandoff::STATUS_QUEUED,
                'transformation_snapshot' => [
                    'approved_minutes' => $request->approved_minutes,
                    'payable_minutes' => $request->payable_minutes,
                    'hours' => $quantity,
                ],
            ]);

            $request->forceFill([
                'status' => AttendanceOvertimeRequest::STATUS_QUEUED_FOR_PAYROLL,
                'queued_for_payroll_at' => now(),
            ])->save();

            return $handoff;
        });
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

    private function findOpenRunFor(AttendanceOvertimeRequest $request): ?PayrollRun
    {
        $occurredOn = $request->starts_at?->toDateString()
            ?? $request->attendanceDay?->attendance_date?->toDateString()
            ?? now()->toDateString();

        return PayrollRun::query()
            ->where('company_id', $request->company_id)
            ->whereIn('status', self::OPEN_RUN_STATUSES)
            ->whereHas('period', function ($query) use ($occurredOn): void {
                $query->where('starts_on', '<=', $occurredOn)
                    ->where('ends_on', '>=', $occurredOn);
            })
            ->with('period')
            ->orderBy('id')
            ->first();
    }

    private function ensureParticipant(PayrollRun $run, int $employeeId): PayrollRunParticipant
    {
        $participant = PayrollRunParticipant::query()
            ->where('payroll_run_id', $run->id)
            ->where('employee_id', $employeeId)
            ->first();

        if ($participant !== null) {
            return $participant;
        }

        return PayrollRunParticipant::query()->create([
            'payroll_run_id' => $run->id,
            'company_id' => $run->company_id,
            'employee_id' => $employeeId,
            'status' => 'included',
            'currency' => $run->currency,
        ]);
    }
}
