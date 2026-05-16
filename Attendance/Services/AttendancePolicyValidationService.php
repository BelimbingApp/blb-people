<?php

namespace App\Modules\People\Attendance\Services;

use App\Modules\People\Attendance\Models\AttendanceAllowanceRule;
use App\Modules\People\Attendance\Models\AttendancePolicyGroup;

class AttendancePolicyValidationService
{
    /**
     * @return array<string, mixed>
     */
    public function validate(AttendancePolicyGroup $policyGroup): array
    {
        $policyGroup->loadMissing('allowanceRules');

        $findings = [
            ...$this->validateIdentity($policyGroup),
            ...$this->validateWorkHourRules($policyGroup->work_hour_rules ?? []),
            ...$this->validateLatenessRules($policyGroup->lateness_rules ?? []),
            ...$this->validateOvertimeExportRules($policyGroup->overtime_export_rules ?? []),
            ...$this->validateAllowanceRules($policyGroup),
        ];

        return [
            'status' => $this->statusFor($findings),
            'policy_group' => [
                'id' => $policyGroup->id,
                'company_id' => $policyGroup->company_id,
                'code' => $policyGroup->code,
                'name' => $policyGroup->name,
                'version' => $policyGroup->version,
                'status' => $policyGroup->status,
            ],
            'summary' => [
                'errors' => $this->countSeverity($findings, 'error'),
                'warnings' => $this->countSeverity($findings, 'warning'),
                'info' => $this->countSeverity($findings, 'info'),
            ],
            'findings' => $findings,
        ];
    }

    /**
     * @return array<int, array<string, string>>
     */
    private function validateIdentity(AttendancePolicyGroup $policyGroup): array
    {
        $findings = [];

        if ($policyGroup->effective_from === null) {
            $findings[] = $this->finding('error', 'policy_effective_from_missing', 'Policy group must have an effective_from date.', 'effective_from');
        }

        if ($policyGroup->effective_to !== null && $policyGroup->effective_from !== null && $policyGroup->effective_to->lt($policyGroup->effective_from)) {
            $findings[] = $this->finding('error', 'policy_effective_range_invalid', 'Policy effective_to must not be earlier than effective_from.', 'effective_to');
        }

        if ($policyGroup->status !== AttendancePolicyGroup::STATUS_ACTIVE) {
            $findings[] = $this->finding('warning', 'policy_not_active', 'Policy group is not active; it will not be selected for active roster resolution.', 'status');
        }

        return $findings;
    }

    /**
     * @param  array<string, mixed>  $rules
     * @return array<int, array<string, string>>
     */
    private function validateWorkHourRules(array $rules): array
    {
        $findings = [];
        $dailyRounding = $rules['daily_rounding'] ?? null;

        if (is_array($dailyRounding)) {
            $findings = [
                ...$findings,
                ...$this->validateRoundingRule($dailyRounding, 'work_hour_rules.daily_rounding'),
            ];
        }

        $breakTreatment = $rules['break_treatment'] ?? null;
        if (is_array($breakTreatment) && array_key_exists('less_break_lateness', $breakTreatment) && ! is_bool($breakTreatment['less_break_lateness'])) {
            $findings[] = $this->finding('warning', 'break_lateness_flag_not_boolean', 'Break lateness treatment should be true or false.', 'work_hour_rules.break_treatment.less_break_lateness');
        }

        return $findings;
    }

    /**
     * @param  array<string, mixed>  $rules
     * @return array<int, array<string, string>>
     */
    private function validateLatenessRules(array $rules): array
    {
        $findings = [];
        $dailyRounding = $rules['daily_rounding'] ?? null;

        if (is_array($dailyRounding)) {
            $findings = [
                ...$findings,
                ...$this->validateRoundingRule($dailyRounding, 'lateness_rules.daily_rounding'),
            ];
        }

        $grace = $rules['grace'] ?? [];
        if (is_array($grace)) {
            foreach (['in', 'out', 'start_break', 'end_break'] as $key) {
                if (array_key_exists($key, $grace) && (! is_numeric($grace[$key]) || (int) $grace[$key] < 0)) {
                    $findings[] = $this->finding('error', 'lateness_grace_invalid', 'Grace minutes must be a non-negative number.', "lateness_rules.grace.{$key}");
                }
            }
        }

        return $findings;
    }

    /**
     * @param  array<string, mixed>  $rules
     * @return array<int, array<string, string>>
     */
    private function validateOvertimeExportRules(array $rules): array
    {
        $findings = [];

        foreach ($rules as $dayType => $rows) {
            if (! is_array($rows)) {
                $findings[] = $this->finding('error', 'overtime_export_rows_invalid', 'Overtime export rows must be a list of mapping rows.', "overtime_export_rules.{$dayType}");

                continue;
            }

            foreach ($rows as $index => $row) {
                if (! is_array($row)) {
                    $findings[] = $this->finding('error', 'overtime_export_row_invalid', 'Overtime export mapping row must be an object.', "overtime_export_rules.{$dayType}.{$index}");

                    continue;
                }

                if (! is_string($row['pay_item_code'] ?? null) || trim($row['pay_item_code']) === '') {
                    $findings[] = $this->finding('error', 'overtime_export_pay_item_missing', 'Overtime export mapping must declare a payroll pay item code.', "overtime_export_rules.{$dayType}.{$index}.pay_item_code");
                }
            }
        }

        return $findings;
    }

    /**
     * @return array<int, array<string, string>>
     */
    private function validateAllowanceRules(AttendancePolicyGroup $policyGroup): array
    {
        return $policyGroup->allowanceRules
            ->flatMap(fn (AttendanceAllowanceRule $rule): array => $this->validateAllowanceRule($rule))
            ->values()
            ->all();
    }

    /**
     * @return array<int, array<string, string>>
     */
    private function validateAllowanceRule(AttendanceAllowanceRule $rule): array
    {
        $findings = [];
        $path = "allowance_rules.{$rule->code}";

        if ($rule->status !== 'active') {
            $findings[] = $this->finding('info', 'allowance_rule_inactive', 'Allowance rule is inactive and will not produce payroll candidates.', "{$path}.status");
        }

        if (! in_array($rule->allowance_type, [AttendanceAllowanceRule::TYPE_DAILY, AttendanceAllowanceRule::TYPE_MONTHLY], true)) {
            $findings[] = $this->finding('error', 'allowance_type_invalid', 'Allowance type must be daily or monthly.', "{$path}.allowance_type");
        }

        if (! in_array($rule->resolution_method, [AttendanceAllowanceRule::RESOLUTION_SUM, AttendanceAllowanceRule::RESOLUTION_MIN, AttendanceAllowanceRule::RESOLUTION_MAX], true)) {
            $findings[] = $this->finding('error', 'allowance_resolution_invalid', 'Allowance resolution method must be sum, min, or max.', "{$path}.resolution_method");
        }

        $rows = $rule->condition_rows ?? [];
        if ($rows === []) {
            $findings[] = $this->finding('warning', 'allowance_conditions_missing', 'Allowance rule has no condition rows.', "{$path}.condition_rows");
        }

        foreach ($rows as $index => $row) {
            if (! is_array($row)) {
                $findings[] = $this->finding('error', 'allowance_condition_invalid', 'Allowance condition row must be an object.', "{$path}.condition_rows.{$index}");

                continue;
            }

            if (! array_key_exists('amount', $row) || ! is_numeric($row['amount'])) {
                $findings[] = $this->finding('error', 'allowance_condition_amount_invalid', 'Allowance condition row must declare a numeric amount.', "{$path}.condition_rows.{$index}.amount");
            }

            if (! is_array($row['predicate'] ?? null)) {
                $findings[] = $this->finding('warning', 'allowance_condition_predicate_missing', 'Allowance condition row should declare typed predicates.', "{$path}.condition_rows.{$index}.predicate");
            }
        }

        return $findings;
    }

    /**
     * @param  array<string, mixed>  $rule
     * @return array<int, array<string, string>>
     */
    private function validateRoundingRule(array $rule, string $path): array
    {
        $findings = [];
        $method = $rule['method'] ?? 'none';
        $minutes = $rule['minutes'] ?? null;

        if (! in_array($method, ['none', 'floor', 'ceiling', 'nearest'], true)) {
            $findings[] = $this->finding('error', 'rounding_method_invalid', 'Rounding method must be none, floor, ceiling, or nearest.', "{$path}.method");
        }

        if ($method !== 'none' && (! is_numeric($minutes) || (int) $minutes < 1 || (int) $minutes > 60)) {
            $findings[] = $this->finding('error', 'rounding_minutes_invalid', 'Rounding minutes must be between 1 and 60.', "{$path}.minutes");
        }

        return $findings;
    }

    /**
     * @return array<string, string>
     */
    private function finding(string $severity, string $code, string $message, string $path): array
    {
        return compact('severity', 'code', 'message', 'path');
    }

    /**
     * @param  array<int, array<string, string>>  $findings
     */
    private function statusFor(array $findings): string
    {
        if ($this->countSeverity($findings, 'error') > 0) {
            return 'error';
        }

        if ($this->countSeverity($findings, 'warning') > 0) {
            return 'warning';
        }

        return 'ok';
    }

    /**
     * @param  array<int, array<string, string>>  $findings
     */
    private function countSeverity(array $findings, string $severity): int
    {
        return count(array_filter($findings, fn (array $finding): bool => $finding['severity'] === $severity));
    }
}
