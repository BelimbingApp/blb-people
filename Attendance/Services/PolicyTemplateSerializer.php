<?php

namespace App\Modules\People\Attendance\Services;

use App\Modules\People\Attendance\Models\AttendancePolicyGroup;

class PolicyTemplateSerializer
{
    public const SCHEMA = 'belimbing.attendance.policy-template.v1';

    /**
     * @return array<string, mixed>
     */
    public function fromPolicyGroup(AttendancePolicyGroup $policy): array
    {
        $work = $policy->work_hour_rules ?? [];
        $lateness = $policy->lateness_rules ?? [];
        $overtime = $policy->overtime_rules ?? [];
        $overtimeExport = $policy->overtime_export_rules ?? [];
        $latenessExport = $policy->lateness_export_rules ?? [];

        return [
            'schema' => self::SCHEMA,
            'code' => str($policy->code)->upper()->toString(),
            'name' => $policy->name,
            'summary' => __('Downloaded from Policy Studio.'),
            'best_for' => __('Use as a reviewed starting point for similar teams.'),
            'currency' => strtoupper($policy->payroll_defaults['currency'] ?? 'MYR'),
            'work_rounding_method' => $work['daily_rounding']['method'] ?? 'nearest',
            'work_rounding_minutes' => (int) ($work['daily_rounding']['minutes'] ?? 15),
            'lateness_rounding_method' => $lateness['daily_rounding']['method'] ?? 'ceiling',
            'lateness_rounding_minutes' => (int) ($lateness['daily_rounding']['minutes'] ?? 5),
            'grace_in' => (int) ($lateness['grace']['in'] ?? 0),
            'grace_out' => (int) ($lateness['grace']['out'] ?? 0),
            'grace_start_break' => (int) ($lateness['grace']['start_break'] ?? 0),
            'grace_end_break' => (int) ($lateness['grace']['end_break'] ?? 0),
            'early_ot_minimum' => (int) ($overtime['early_ot']['minimum_minutes'] ?? 60),
            'late_ot_minimum' => (int) ($overtime['late_ot']['minimum_minutes'] ?? 60),
            'normal_ot_pay_item' => $overtimeExport['normal'][0]['pay_item_code'] ?? 'overtime',
            'extended_ot_pay_item' => $overtimeExport['normal'][1]['pay_item_code'] ?? 'overtime_extended',
            'rest_day_ot_pay_item' => $overtimeExport['rest_day'][0]['pay_item_code'] ?? 'rest_day_overtime',
            'holiday_ot_pay_item' => $overtimeExport['holiday'][0]['pay_item_code'] ?? 'holiday_overtime',
            'lateness_pay_item' => $latenessExport['pay_item_code'] ?? 'lateness_deduction',
        ];
    }

    public function toJson(array $template): string
    {
        return (string) json_encode($template, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }
}
