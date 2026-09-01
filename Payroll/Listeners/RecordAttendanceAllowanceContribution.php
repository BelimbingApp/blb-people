<?php

namespace App\Domains\People\Payroll\Listeners;

use App\Domains\People\Attendance\Events\AttendanceAllowanceMaterialized;
use App\Domains\People\Attendance\Models\AttendanceAllowanceRule;
use App\Domains\People\Payroll\Contracts\Intake\PayrollContributionPayload;
use App\Domains\People\Payroll\Models\PayrollAttendanceRulePayItem;
use App\Domains\People\Payroll\Services\PayrollContributionIntake;

/**
 * Translates an attendance allowance materialisation into a payroll
 * contribution.
 *
 * The pay-item code for the rule lives in a Payroll-owned mapping table
 * (`people_payroll_attendance_rule_pay_items`). The listener picks the
 * row whose `effective_from` is the latest one not after the
 * contribution date.
 *
 * If no mapping exists for the rule, the contribution is dropped
 * silently — the materialisation event itself is still useful for audit
 * listeners; only the payroll write is skipped.
 */
class RecordAttendanceAllowanceContribution
{
    public const SOURCE_TYPE = 'attendance_allowance_rule';

    public function __construct(
        private readonly PayrollContributionIntake $intake,
    ) {}

    public function handle(AttendanceAllowanceMaterialized $event): void
    {
        if ($event->amount <= 0.0) {
            return;
        }

        $rule = AttendanceAllowanceRule::query()->find($event->attendanceAllowanceRuleId);
        if ($rule === null) {
            return;
        }

        $payItemCode = $this->resolvePayItemCode($event);
        if ($payItemCode === null) {
            return;
        }

        $payload = new PayrollContributionPayload(
            sourceType: self::SOURCE_TYPE,
            sourceId: $event->attendanceAllowanceRuleId,
            payItemCode: $payItemCode,
            periodAnchor: $event->occurredOn,
            companyId: $event->companyId,
            employeeId: $event->employeeId,
            currency: 'MYR',
            occurredOn: $event->occurredOn,
            inputType: 'earning',
            amount: $event->amount,
            quantity: null,
            rate: null,
            label: (string) ($rule->name ?? 'Attendance allowance'),
            accountingSnapshot: [],
            metadata: [
                'attendance_allowance_rule_id' => $event->attendanceAllowanceRuleId,
                'attendance_day_id' => $event->attendanceDayId,
                'allowance_type' => $rule->allowance_type,
            ],
        );

        $this->intake->ingest($payload);
    }

    private function resolvePayItemCode(AttendanceAllowanceMaterialized $event): ?string
    {
        $occurredOn = $event->occurredOn->format('Y-m-d');

        $mapping = PayrollAttendanceRulePayItem::query()
            ->where('attendance_allowance_rule_id', $event->attendanceAllowanceRuleId)
            ->whereDate('effective_from', '<=', $occurredOn)
            ->where(function ($query) use ($occurredOn): void {
                $query->whereNull('effective_to')
                    ->orWhereDate('effective_to', '>', $occurredOn);
            })
            ->orderByDesc('effective_from')
            ->first();

        $payItemCode = $mapping?->payroll_pay_item_code;

        return is_string($payItemCode) && $payItemCode !== '' ? $payItemCode : null;
    }
}
