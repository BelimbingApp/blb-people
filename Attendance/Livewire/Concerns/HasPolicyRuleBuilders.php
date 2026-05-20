<?php

namespace App\Modules\People\Attendance\Livewire\Concerns;

use App\Modules\People\Attendance\Models\AttendancePolicyGroup;

trait HasPolicyRuleBuilders
{
    /** @param array<string, mixed> $validated */
    private function policyWorkHourRules(array $validated): array
    {
        return [
            'daily_rounding' => $this->roundingRule($validated['policyWorkRoundingMethod'], $validated['policyWorkRoundingMinutes']),
            'daily_rated_workday_counts' => ['paid_rest_day' => false, 'paid_off_day' => false, 'paid_holiday' => false],
            'break_treatment' => [
                'monthly_exclude_break_hours' => $this->policyExcludeBreakFromWork,
                'daily_exclude_break_hours' => $this->policyExcludeBreakFromWork,
                'less_break_lateness' => $this->policyLessBreakLateness,
            ],
        ];
    }

    /** @param array<string, mixed> $validated */
    private function policyLatenessRules(array $validated): array
    {
        return [
            'daily_rounding' => $this->roundingRule($validated['policyLatenessRoundingMethod'], $validated['policyLatenessRoundingMinutes']),
            'grace' => [
                'in' => (int) $validated['policyGraceIn'],
                'out' => (int) $validated['policyGraceOut'],
                'start_break' => (int) $validated['policyGraceStartBreak'],
                'end_break' => (int) $validated['policyGraceEndBreak'],
            ],
        ];
    }

    /** @param array<string, mixed> $validated */
    private function policyOvertimeRules(array $validated): array
    {
        return [
            'early_ot' => ['enabled' => $this->policyEarlyOvertimeEnabled, 'minimum_minutes' => (int) $validated['policyEarlyOvertimeMinimumMinutes']],
            'late_ot' => ['enabled' => $this->policyLateOvertimeEnabled, 'minimum_minutes' => (int) $validated['policyLateOvertimeMinimumMinutes']],
            'day_types' => [
                'normal' => $this->policyNormalDayOvertime,
                'holiday' => $this->policyHolidayOvertime,
                'rest_day' => $this->policyRestDayOvertime,
                'off_day' => $this->policyOffDayOvertime,
            ],
            'adjustment_bands' => [['from' => 0, 'to' => 60, 'operation' => 'set', 'minutes' => 0, 'day_types' => ['normal']]],
            'knock_off' => ['lateness' => $this->policyKnockOffLateness, 'npl' => $this->policyKnockOffNpl],
        ];
    }

    /** @param array<string, mixed> $validated */
    private function policyOvertimeExportRules(array $validated): array
    {
        return [
            'normal' => array_values(array_filter([
                ['lte_hours' => 2, 'pay_item_code' => $validated['policyNormalOvertimePayItem']],
                $this->blankToNull($validated['policyExtendedOvertimePayItem'] ?? null) === null ? null : ['lte_hours' => null, 'pay_item_code' => $validated['policyExtendedOvertimePayItem']],
            ])),
            'rest_day' => $this->blankToNull($validated['policyRestDayOvertimePayItem'] ?? null) === null ? [] : [['lte_hours' => null, 'pay_item_code' => $validated['policyRestDayOvertimePayItem']]],
            'holiday' => $this->blankToNull($validated['policyHolidayOvertimePayItem'] ?? null) === null ? [] : [['lte_hours' => null, 'pay_item_code' => $validated['policyHolidayOvertimePayItem']]],
        ];
    }

    /** @param array<string, mixed> $validated */
    private function policyLatenessExportRules(array $validated): array
    {
        return [
            'monthly_rounding' => $this->roundingRule($validated['policyLatenessMonthlyRoundingMethod'], $validated['policyLatenessMonthlyRoundingMinutes']),
            'pay_item_code' => $validated['policyLatenessPayItem'],
        ];
    }

    private function roundingRule(string $method, mixed $minutes): array
    {
        return ['method' => $method, 'minutes' => $method === 'none' ? null : (int) $minutes];
    }

    private function loadPolicyRules(AttendancePolicyGroup $policy): void
    {
        $work = $policy->work_hour_rules ?? [];
        $lateness = $policy->lateness_rules ?? [];
        $overtime = $policy->overtime_rules ?? [];
        $overtimeExport = $policy->overtime_export_rules ?? [];
        $latenessExport = $policy->lateness_export_rules ?? [];
        $this->policyWorkRoundingMethod = $work['daily_rounding']['method'] ?? 'nearest';
        $this->policyWorkRoundingMinutes = (string) ($work['daily_rounding']['minutes'] ?? 15);
        $this->policyLatenessRoundingMethod = $lateness['daily_rounding']['method'] ?? 'ceiling';
        $this->policyLatenessRoundingMinutes = (string) ($lateness['daily_rounding']['minutes'] ?? 5);
        $this->policyGraceIn = (string) ($lateness['grace']['in'] ?? 0);
        $this->policyGraceOut = (string) ($lateness['grace']['out'] ?? 0);
        $this->policyGraceStartBreak = (string) ($lateness['grace']['start_break'] ?? 0);
        $this->policyGraceEndBreak = (string) ($lateness['grace']['end_break'] ?? 0);
        $this->policyExcludeBreakFromWork = (bool) ($work['break_treatment']['daily_exclude_break_hours'] ?? true);
        $this->policyLessBreakLateness = (bool) ($work['break_treatment']['less_break_lateness'] ?? true);
        $this->policyEarlyOvertimeEnabled = (bool) ($overtime['early_ot']['enabled'] ?? true);
        $this->policyEarlyOvertimeMinimumMinutes = (string) ($overtime['early_ot']['minimum_minutes'] ?? 60);
        $this->policyLateOvertimeEnabled = (bool) ($overtime['late_ot']['enabled'] ?? true);
        $this->policyLateOvertimeMinimumMinutes = (string) ($overtime['late_ot']['minimum_minutes'] ?? 60);
        $this->policyNormalOvertimePayItem = $overtimeExport['normal'][0]['pay_item_code'] ?? 'overtime';
        $this->policyExtendedOvertimePayItem = $overtimeExport['normal'][1]['pay_item_code'] ?? 'overtime_extended';
        $this->policyRestDayOvertimePayItem = $overtimeExport['rest_day'][0]['pay_item_code'] ?? 'rest_day_overtime';
        $this->policyHolidayOvertimePayItem = $overtimeExport['holiday'][0]['pay_item_code'] ?? 'holiday_overtime';
        $this->policyLatenessPayItem = $latenessExport['pay_item_code'] ?? 'lateness_deduction';
        $this->policyLatenessMonthlyRoundingMethod = $latenessExport['monthly_rounding']['method'] ?? 'ceiling';
        $this->policyLatenessMonthlyRoundingMinutes = (string) ($latenessExport['monthly_rounding']['minutes'] ?? 15);
    }
}
