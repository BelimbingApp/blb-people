<?php

namespace App\Modules\People\Attendance\Services;

use App\Modules\People\Attendance\Models\AttendanceClockEvent;
use App\Modules\People\Attendance\Models\AttendanceDay;
use App\Modules\People\Attendance\Models\AttendancePolicyGroup;
use App\Modules\People\Attendance\Models\AttendanceShiftTemplate;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Projects an `AttendanceDay`'s metrics from its clock events, shift, and policy group.
 *
 * What this service does, in order:
 *
 * 1. Base worked minutes  = last clock-out − first clock-in.
 * 2. Unpaid break deduction = sum of overlap minutes between (work span) and each
 *    `shift.break_windows` entry whose `paid` flag is false. Paid breaks are
 *    left in worked time.
 * 3. Worked-minute rounding  = `policy.work_hour_rules.daily_rounding` applied
 *    to the post-deduction worked minutes.
 * 4. Late minutes            = (first clock-in − shift start − grace.in),
 *    floored at zero, then rounded by `policy.lateness_rules.daily_rounding`.
 * 5. Early-out minutes       = (shift end − last clock-out − grace.out),
 *    floored at zero, then rounded by `policy.lateness_rules.daily_rounding`.
 * 6. Overtime candidate      = max(0, worked − expected), but suppressed entirely
 *    when the excess is below `policy.overtime_rules.late_ot.minimum_minutes`.
 *
 * When the day has no policy group, or the policy carries no rules, every
 * policy-driven step degrades to "no adjustment" — output matches what raw
 * clock arithmetic would produce.
 */
class AttendanceDayProjectionService
{
    public function project(AttendanceDay $day): AttendanceDay
    {
        $day->loadMissing(['shiftTemplate', 'clockEvents', 'policyGroup']);

        $shift = $day->shiftTemplate;
        $policy = $day->policyGroup;
        $events = $day->clockEvents->sortBy('occurred_at')->values();

        $expectedMinutes = $shift?->expected_work_minutes ?? $day->expected_minutes;

        [$rawWorkedMinutes, $unpaidBreakMinutes, $totalBreakMinutes] = $this->workSpanMinutes($shift, $events);
        $workedMinutes = $this->applyRounding($rawWorkedMinutes, $this->workHourRounding($policy));

        $lateMinutes = $this->applyRounding(
            $this->lateMinutes($shift, $events, $this->graceMinutes($policy, 'in')),
            $this->latenessRounding($policy),
        );
        $earlyOutMinutes = $this->applyRounding(
            $this->earlyOutMinutes($shift, $events, $this->graceMinutes($policy, 'out')),
            $this->latenessRounding($policy),
        );

        $absentMinutes = $events->isEmpty() ? $expectedMinutes : 0;

        $rawOvertimeCandidate = max(0, $workedMinutes - $expectedMinutes);
        $overtimeCandidate = $rawOvertimeCandidate >= $this->overtimeMinimumMinutes($policy)
            ? $rawOvertimeCandidate
            : 0;

        $hasClockIn = $events->contains('event_type', AttendanceClockEvent::TYPE_IN);
        $hasClockOut = $events->contains('event_type', AttendanceClockEvent::TYPE_OUT);

        $exceptionTags = [];
        if ($events->isEmpty()) {
            $exceptionTags[] = 'missing_clock_events';
        }
        if (! $events->isEmpty() && ! $hasClockIn) {
            $exceptionTags[] = 'missing_clock_in';
        }
        if (! $events->isEmpty() && ! $hasClockOut) {
            $exceptionTags[] = 'missing_clock_out';
        }
        if ($lateMinutes > 0) {
            $exceptionTags[] = 'late_in';
        }
        if ($earlyOutMinutes > 0) {
            $exceptionTags[] = 'early_out';
        }

        $day->fill([
            'status' => $exceptionTags === [] ? AttendanceDay::STATUS_READY_FOR_REVIEW : AttendanceDay::STATUS_EXCEPTION_PENDING,
            'expected_minutes' => $expectedMinutes,
            'worked_minutes' => $workedMinutes,
            'payable_minutes' => min($workedMinutes, $expectedMinutes),
            'late_minutes' => $lateMinutes,
            'early_out_minutes' => $earlyOutMinutes,
            'absent_minutes' => $absentMinutes,
            'break_minutes' => $totalBreakMinutes,
            'overtime_candidate_minutes' => $overtimeCandidate,
            'exception_tags' => $exceptionTags,
            'projection_snapshot' => [
                'clock_event_ids' => $events->pluck('id')->all(),
                'shift_template_id' => $shift?->id,
                'policy_group_id' => $policy?->id,
                'raw_worked_minutes' => $rawWorkedMinutes,
                'unpaid_break_minutes' => $unpaidBreakMinutes,
                'projected_at' => now()->toIso8601String(),
            ],
        ]);

        return $day;
    }

    /**
     * @param  Collection<int, AttendanceClockEvent>  $events
     * @return array{0: int, 1: int, 2: int} [worked minutes after break deduction, unpaid break minutes, total break minutes]
     */
    private function workSpanMinutes(?AttendanceShiftTemplate $shift, Collection $events): array
    {
        $first = $events->firstWhere('event_type', AttendanceClockEvent::TYPE_IN);
        $last = $events->reverse()->firstWhere('event_type', AttendanceClockEvent::TYPE_OUT);

        if ($first === null || $last === null) {
            return [0, 0, 0];
        }

        $start = CarbonImmutable::parse($first->occurred_at);
        $end = CarbonImmutable::parse($last->occurred_at);
        if ($end->lessThanOrEqualTo($start)) {
            return [0, 0, 0];
        }

        $rawSpan = (int) $start->diffInMinutes($end);
        $unpaid = 0;
        $total = 0;

        foreach ($shift?->break_windows ?? [] as $break) {
            if (! is_array($break)) {
                continue;
            }
            [$breakStart, $breakEnd] = $this->breakWindowOn($break, $shift, $start);
            if ($breakStart === null || $breakEnd === null) {
                continue;
            }
            $overlap = $this->overlapMinutes($start, $end, $breakStart, $breakEnd);
            $total += $overlap;
            if (! (bool) ($break['paid'] ?? false)) {
                $unpaid += $overlap;
            }
        }

        return [max(0, $rawSpan - $unpaid), $unpaid, $total];
    }

    /**
     * @param  array<string, mixed>  $break
     * @return array{0: ?CarbonImmutable, 1: ?CarbonImmutable}
     */
    private function breakWindowOn(array $break, ?AttendanceShiftTemplate $shift, CarbonImmutable $workStart): array
    {
        $startStr = $break['starts_at'] ?? null;
        $endStr = $break['ends_at'] ?? null;
        if (! is_string($startStr) || ! is_string($endStr) || $startStr === '' || $endStr === '') {
            return [null, null];
        }

        $startStr = substr($startStr, 0, 5);
        $endStr = substr($endStr, 0, 5);
        $date = $workStart->toDateString();
        $shiftStartStr = $shift !== null ? substr((string) $shift->starts_at, 0, 5) : null;

        $breakStart = CarbonImmutable::parse($date.' '.$startStr);
        $breakEnd = CarbonImmutable::parse($date.' '.$endStr);

        // Cross-midnight shift: break time-of-day numerically before shift start belongs to the next calendar day.
        if ($shift?->crosses_midnight && $shiftStartStr !== null && $startStr < $shiftStartStr) {
            $breakStart = $breakStart->addDay();
            $breakEnd = $breakEnd->addDay();
        }

        // Break that itself crosses midnight (start later than end same-day): treat end as next day.
        if ($breakEnd->lessThanOrEqualTo($breakStart)) {
            $breakEnd = $breakEnd->addDay();
        }

        return [$breakStart, $breakEnd];
    }

    private function overlapMinutes(CarbonImmutable $a, CarbonImmutable $b, CarbonImmutable $c, CarbonImmutable $d): int
    {
        $start = $a->greaterThan($c) ? $a : $c;
        $end = $b->lessThan($d) ? $b : $d;
        if ($end->lessThanOrEqualTo($start)) {
            return 0;
        }

        return (int) $start->diffInMinutes($end);
    }

    /** @param  Collection<int, AttendanceClockEvent>  $events */
    private function lateMinutes(?AttendanceShiftTemplate $shift, Collection $events, int $graceMinutes): int
    {
        $clockIn = $events->firstWhere('event_type', AttendanceClockEvent::TYPE_IN);
        if ($shift === null || $clockIn === null) {
            return 0;
        }

        $expected = CarbonImmutable::parse($clockIn->occurred_at)->setTimeFromTimeString($shift->starts_at);
        $deltaMinutes = (int) $expected->diffInMinutes(CarbonImmutable::parse($clockIn->occurred_at), false);

        return max(0, $deltaMinutes - $graceMinutes);
    }

    /** @param  Collection<int, AttendanceClockEvent>  $events */
    private function earlyOutMinutes(?AttendanceShiftTemplate $shift, Collection $events, int $graceMinutes): int
    {
        $clockOut = $events->reverse()->firstWhere('event_type', AttendanceClockEvent::TYPE_OUT);
        if ($shift === null || $clockOut === null) {
            return 0;
        }

        $actual = CarbonImmutable::parse($clockOut->occurred_at);
        $expected = $actual->setTimeFromTimeString($shift->ends_at);
        if ($shift->crosses_midnight && $expected->lessThan($actual->startOfDay())) {
            $expected = $expected->addDay();
        }
        $deltaMinutes = (int) $actual->diffInMinutes($expected, false);

        return max(0, $deltaMinutes - $graceMinutes);
    }

    /** @param  array{method?: string, minutes?: int|string|null}|null  $rule */
    private function applyRounding(int $minutes, ?array $rule): int
    {
        if (! is_array($rule)) {
            return $minutes;
        }
        $method = $rule['method'] ?? 'none';
        if ($method === 'none' || $method === null || ! isset($rule['minutes'])) {
            return $minutes;
        }
        $block = max(1, (int) $rule['minutes']);

        return match ($method) {
            'floor' => intdiv($minutes, $block) * $block,
            'ceiling' => (int) ceil($minutes / $block) * $block,
            'nearest' => (int) round($minutes / $block) * $block,
            default => $minutes,
        };
    }

    /** @return array{method?: string, minutes?: int}|null */
    private function workHourRounding(?AttendancePolicyGroup $policy): ?array
    {
        $rule = $policy?->work_hour_rules['daily_rounding'] ?? null;

        return is_array($rule) ? $rule : null;
    }

    /** @return array{method?: string, minutes?: int}|null */
    private function latenessRounding(?AttendancePolicyGroup $policy): ?array
    {
        $rule = $policy?->lateness_rules['daily_rounding'] ?? null;

        return is_array($rule) ? $rule : null;
    }

    private function graceMinutes(?AttendancePolicyGroup $policy, string $side): int
    {
        $value = $policy?->lateness_rules['grace'][$side] ?? 0;

        return is_numeric($value) ? max(0, (int) $value) : 0;
    }

    private function overtimeMinimumMinutes(?AttendancePolicyGroup $policy): int
    {
        $value = $policy?->overtime_rules['late_ot']['minimum_minutes'] ?? 0;

        return is_numeric($value) ? max(0, (int) $value) : 0;
    }
}
