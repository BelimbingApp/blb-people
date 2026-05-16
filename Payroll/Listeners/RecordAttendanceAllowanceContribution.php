<?php

namespace App\Modules\People\Payroll\Listeners;

use App\Modules\People\Attendance\Events\AttendanceAllowanceMaterialized;
use App\Modules\People\Attendance\Models\AttendanceAllowanceRule;
use App\Modules\People\Payroll\Contracts\Intake\PayrollContributionPayload;
use App\Modules\People\Payroll\Services\PayrollContributionIntake;

/**
 * Translates an attendance allowance materialisation into a payroll
 * contribution.
 *
 * Phase 1: reads `payroll_pay_item_code` directly off the
 * AttendanceAllowanceRule row. Phase 2 of plan 12 will switch this to a
 * Payroll-owned mapping table; until then the column on the attendance
 * rule is the source of truth.
 *
 * If no pay-item code is configured for the rule, the contribution is
 * dropped silently — the materialisation event itself is still useful
 * for audit listeners; only the payroll write is skipped.
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

        $payItemCode = $rule->payroll_pay_item_code;
        if (! is_string($payItemCode) || $payItemCode === '') {
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
}
