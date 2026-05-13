<?php

namespace App\Modules\People\Attendance\Services;

use App\Modules\People\Attendance\Models\AttendanceClockEvent;
use App\Modules\People\Attendance\Models\AttendanceDay;
use App\Modules\People\Attendance\Models\AttendanceShiftTemplate;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

class AttendanceDayProjectionService
{
    public function project(AttendanceDay $day): AttendanceDay
    {
        $day->loadMissing(['shiftTemplate', 'clockEvents']);

        $shift = $day->shiftTemplate;
        $events = $day->clockEvents->sortBy('occurred_at')->values();

        $workedMinutes = $this->workedMinutes($events);
        $expectedMinutes = $shift?->expected_work_minutes ?? $day->expected_minutes;
        $lateMinutes = $this->lateMinutes($shift, $events);
        $earlyOutMinutes = $this->earlyOutMinutes($shift, $events);
        $absentMinutes = $events->isEmpty() ? $expectedMinutes : 0;
        $overtimeCandidate = max(0, $workedMinutes - $expectedMinutes);

        $exceptionTags = [];
        if ($events->isEmpty()) {
            $exceptionTags[] = 'missing_clock_events';
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
            'overtime_candidate_minutes' => $overtimeCandidate,
            'exception_tags' => $exceptionTags,
            'projection_snapshot' => [
                'clock_event_ids' => $events->pluck('id')->all(),
                'shift_template_id' => $shift?->id,
                'projected_at' => now()->toIso8601String(),
            ],
        ]);

        return $day;
    }

    /** @param  Collection<int, AttendanceClockEvent>  $events */
    private function workedMinutes(Collection $events): int
    {
        $first = $events->firstWhere('event_type', AttendanceClockEvent::TYPE_IN);
        $last = $events->reverse()->firstWhere('event_type', AttendanceClockEvent::TYPE_OUT);

        if ($first === null || $last === null) {
            return 0;
        }

        return max(0, (int) CarbonImmutable::parse($first->occurred_at)->diffInMinutes(CarbonImmutable::parse($last->occurred_at)));
    }

    /** @param  Collection<int, AttendanceClockEvent>  $events */
    private function lateMinutes(?AttendanceShiftTemplate $shift, Collection $events): int
    {
        $clockIn = $events->firstWhere('event_type', AttendanceClockEvent::TYPE_IN);
        if ($shift === null || $clockIn === null) {
            return 0;
        }

        $expected = CarbonImmutable::parse($clockIn->occurred_at)->setTimeFromTimeString($shift->starts_at);

        return max(0, (int) $expected->diffInMinutes(CarbonImmutable::parse($clockIn->occurred_at), false));
    }

    /** @param  Collection<int, AttendanceClockEvent>  $events */
    private function earlyOutMinutes(?AttendanceShiftTemplate $shift, Collection $events): int
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

        return max(0, (int) $actual->diffInMinutes($expected, false));
    }
}
