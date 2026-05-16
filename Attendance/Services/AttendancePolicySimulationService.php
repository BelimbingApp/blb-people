<?php

namespace App\Modules\People\Attendance\Services;

use App\Modules\People\Attendance\Models\AttendanceAllowanceRule;
use App\Modules\People\Attendance\Models\AttendancePolicyGroup;
use App\Modules\People\Attendance\Models\AttendanceShiftTemplate;
use Carbon\CarbonImmutable;

class AttendancePolicySimulationService
{
    /**
     * @return array<string, mixed>
     */
    public function simulate(
        AttendancePolicyGroup $policyGroup,
        AttendanceShiftTemplate $shiftTemplate,
        string $attendanceDate,
        string $clockIn,
        string $clockOut,
    ): array {
        $policyGroup->loadMissing('allowanceRules');

        $date = CarbonImmutable::parse($attendanceDate)->toDateString();
        $shiftStartsAt = CarbonImmutable::parse($date.' '.$shiftTemplate->starts_at);
        $shiftEndsAt = CarbonImmutable::parse($date.' '.$shiftTemplate->ends_at);
        if ($shiftTemplate->crosses_midnight || $shiftEndsAt->lessThanOrEqualTo($shiftStartsAt)) {
            $shiftEndsAt = $shiftEndsAt->addDay();
        }

        $clockInAt = CarbonImmutable::parse($date.' '.$clockIn);
        $clockOutAt = CarbonImmutable::parse($date.' '.$clockOut);
        if ($clockOutAt->lessThanOrEqualTo($clockInAt)) {
            $clockOutAt = $clockOutAt->addDay();
        }

        $graceIn = (int) data_get($policyGroup->lateness_rules ?? [], 'grace.in', 0);
        $workedMinutes = max(0, (int) $clockInAt->diffInMinutes($clockOutAt));
        $lateMinutes = max(0, (int) $shiftStartsAt->diffInMinutes($clockInAt, false) - $graceIn);
        $earlyOutMinutes = max(0, (int) $clockOutAt->diffInMinutes($shiftEndsAt, false));
        $expectedMinutes = (int) $shiftTemplate->expected_work_minutes;
        $payableMinutes = min($workedMinutes, $expectedMinutes);
        $overtimeCandidateMinutes = max(0, $workedMinutes - $expectedMinutes);

        $exceptionTags = [];
        if ($lateMinutes > 0) {
            $exceptionTags[] = 'late_in';
        }
        if ($earlyOutMinutes > 0) {
            $exceptionTags[] = 'early_out';
        }

        return [
            'status' => $exceptionTags === [] ? 'ok' : 'warning',
            'policy_group' => [
                'id' => $policyGroup->id,
                'code' => $policyGroup->code,
                'name' => $policyGroup->name,
                'version' => $policyGroup->version,
            ],
            'shift_template' => [
                'id' => $shiftTemplate->id,
                'code' => $shiftTemplate->code,
                'name' => $shiftTemplate->name,
                'starts_at' => $shiftTemplate->starts_at,
                'ends_at' => $shiftTemplate->ends_at,
                'crosses_midnight' => $shiftTemplate->crosses_midnight,
            ],
            'input' => [
                'attendance_date' => $date,
                'clock_in' => $clockInAt->toDateTimeString(),
                'clock_out' => $clockOutAt->toDateTimeString(),
            ],
            'metrics' => [
                'expected_minutes' => $expectedMinutes,
                'worked_minutes' => $workedMinutes,
                'payable_minutes' => $payableMinutes,
                'late_minutes' => $lateMinutes,
                'early_out_minutes' => $earlyOutMinutes,
                'overtime_candidate_minutes' => $overtimeCandidateMinutes,
                'exception_tags' => $exceptionTags,
            ],
            'allowance_candidates' => $this->allowanceCandidates($policyGroup, $shiftTemplate, $clockOutAt, $workedMinutes),
            'explanation' => $this->explanation($lateMinutes, $earlyOutMinutes, $overtimeCandidateMinutes, $exceptionTags),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function allowanceCandidates(AttendancePolicyGroup $policyGroup, AttendanceShiftTemplate $shiftTemplate, CarbonImmutable $clockOutAt, int $workedMinutes): array
    {
        return $policyGroup->allowanceRules
            ->filter(fn (AttendanceAllowanceRule $rule): bool => $rule->status === 'active' && $rule->allowance_type === AttendanceAllowanceRule::TYPE_DAILY)
            ->filter(fn (AttendanceAllowanceRule $rule): bool => $rule->attendance_shift_template_id === null || $rule->attendance_shift_template_id === $shiftTemplate->id)
            ->map(fn (AttendanceAllowanceRule $rule): ?array => $this->allowanceCandidate($rule, $clockOutAt, $workedMinutes))
            ->filter()
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>|null
     */
    private function allowanceCandidate(AttendanceAllowanceRule $rule, CarbonImmutable $clockOutAt, int $workedMinutes): ?array
    {
        $matches = [];

        foreach ($rule->condition_rows ?? [] as $index => $row) {
            if (! is_array($row) || ! is_array($row['predicate'] ?? null)) {
                continue;
            }

            if ($this->predicateMatches($row['predicate'], $clockOutAt, $workedMinutes)) {
                $matches[] = [
                    'index' => $index,
                    'description' => $row['description'] ?? null,
                    'amount' => $row['amount'] ?? null,
                ];
            }
        }

        if ($matches === []) {
            return null;
        }

        return [
            'code' => $rule->code,
            'name' => $rule->name,
            'resolution_method' => $rule->resolution_method,
            'matched_rows' => $matches,
        ];
    }

    /**
     * @param  array<string, mixed>  $predicate
     */
    private function predicateMatches(array $predicate, CarbonImmutable $clockOutAt, int $workedMinutes): bool
    {
        $minWorkedMinutes = $predicate['min_worked_minutes'] ?? null;
        if (is_numeric($minWorkedMinutes) && $workedMinutes < (int) $minWorkedMinutes) {
            return false;
        }

        $afterMatches = $this->timePredicateMatches($predicate['clock_out_after'] ?? null, $clockOutAt, 'after');
        $beforeMatches = $this->timePredicateMatches($predicate['clock_out_before'] ?? null, $clockOutAt, 'before');

        if (array_key_exists('clock_out_after', $predicate) && array_key_exists('clock_out_before', $predicate)) {
            return $afterMatches || $beforeMatches;
        }

        if (array_key_exists('clock_out_after', $predicate)) {
            return $afterMatches;
        }

        if (array_key_exists('clock_out_before', $predicate)) {
            return $beforeMatches;
        }

        return true;
    }

    private function timePredicateMatches(mixed $time, CarbonImmutable $actual, string $operator): bool
    {
        if (! is_string($time) || $time === '') {
            return false;
        }

        $actualMinutes = ((int) $actual->format('H')) * 60 + (int) $actual->format('i');
        [$hour, $minute] = array_map('intval', explode(':', $time.':0'));
        $targetMinutes = ($hour * 60) + $minute;

        return $operator === 'after'
            ? $actualMinutes >= $targetMinutes
            : $actualMinutes <= $targetMinutes;
    }

    /**
     * @param  array<int, string>  $exceptionTags
     */
    private function explanation(int $lateMinutes, int $earlyOutMinutes, int $overtimeCandidateMinutes, array $exceptionTags): string
    {
        if ($exceptionTags === [] && $overtimeCandidateMinutes === 0) {
            return 'Clock times fit the selected shift without attendance exceptions or overtime candidates.';
        }

        $parts = [];
        if ($lateMinutes > 0) {
            $parts[] = "Clock-in is {$lateMinutes} minute(s) late after grace.";
        }
        if ($earlyOutMinutes > 0) {
            $parts[] = "Clock-out is {$earlyOutMinutes} minute(s) before scheduled end.";
        }
        if ($overtimeCandidateMinutes > 0) {
            $parts[] = "Worked time exceeds expected work minutes by {$overtimeCandidateMinutes} minute(s); this is only an overtime candidate until approved.";
        }

        return implode(' ', $parts);
    }
}
