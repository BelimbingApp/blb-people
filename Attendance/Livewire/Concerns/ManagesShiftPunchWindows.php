<?php

namespace App\Modules\People\Attendance\Livewire\Concerns;

use App\Modules\People\Attendance\Models\AttendancePunchWindow;
use App\Modules\People\Attendance\Models\AttendanceShiftTemplate;

trait ManagesShiftPunchWindows
{
    private function loadPunchWindows(AttendanceShiftTemplate $shift): void
    {
        $in = $shift->punchWindows->firstWhere('event_type', AttendancePunchWindow::TYPE_IN);
        $out = $shift->punchWindows->firstWhere('event_type', AttendancePunchWindow::TYPE_OUT);

        $this->shiftInWindowBeforeMinutes = (string) $this->minutesBetween((string) ($in?->earliest_at ?? $shift->starts_at), (string) $shift->starts_at);
        $this->shiftInWindowAfterMinutes = (string) $this->minutesBetween((string) $shift->starts_at, (string) ($in?->latest_at ?? $shift->starts_at));
        $this->shiftOutWindowBeforeMinutes = (string) $this->minutesBetween((string) ($out?->earliest_at ?? $shift->ends_at), (string) $shift->ends_at);
        $this->shiftOutWindowAfterMinutes = (string) $this->minutesBetween((string) $shift->ends_at, (string) ($out?->latest_at ?? $shift->ends_at));
    }

    /** @param array<string, mixed> $validated */
    private function syncPunchWindows(AttendanceShiftTemplate $shift, array $validated): void
    {
        $breakWindows = $this->breakWindows($validated);
        $windows = [
            [AttendancePunchWindow::TYPE_IN, $validated['shiftStartsAt'], $this->timeMinusMinutes($validated['shiftStartsAt'], (int) $validated['shiftInWindowBeforeMinutes']), $this->timePlusMinutes($validated['shiftStartsAt'], (int) $validated['shiftInWindowAfterMinutes']), 10],
            [AttendancePunchWindow::TYPE_OUT, $validated['shiftEndsAt'], $this->timeMinusMinutes($validated['shiftEndsAt'], (int) $validated['shiftOutWindowBeforeMinutes']), $this->timePlusMinutes($validated['shiftEndsAt'], (int) $validated['shiftOutWindowAfterMinutes']), 40],
        ];

        if (isset($breakWindows[0])) {
            $windows[] = [AttendancePunchWindow::TYPE_BREAK_OUT, $breakWindows[0]['starts_at'], $breakWindows[0]['starts_at'], $breakWindows[0]['ends_at'], 20];
            $windows[] = [AttendancePunchWindow::TYPE_BREAK_IN, $breakWindows[0]['ends_at'], $breakWindows[0]['starts_at'], $breakWindows[0]['ends_at'], 30];
        }

        if (isset($breakWindows[1])) {
            $windows[] = [AttendancePunchWindow::TYPE_BREAK_OUT_2, $breakWindows[1]['starts_at'], $breakWindows[1]['starts_at'], $breakWindows[1]['ends_at'], 22];
            $windows[] = [AttendancePunchWindow::TYPE_BREAK_IN_2, $breakWindows[1]['ends_at'], $breakWindows[1]['starts_at'], $breakWindows[1]['ends_at'], 32];
        }

        $seenTypes = [];
        foreach ($windows as [$type, $expectedAt, $earliestAt, $latestAt, $sortOrder]) {
            $seenTypes[] = $type;
            AttendancePunchWindow::query()->updateOrCreate(
                ['attendance_shift_template_id' => $shift->id, 'event_type' => $type],
                [
                    'expected_at' => $expectedAt,
                    'earliest_at' => $earliestAt,
                    'latest_at' => $latestAt,
                    'required' => in_array($type, [AttendancePunchWindow::TYPE_IN, AttendancePunchWindow::TYPE_OUT], true),
                    'exception_on_unmatched' => true,
                    'sort_order' => $sortOrder,
                    'metadata' => ['created_from' => 'attendance_shift_builder'],
                ],
            );
        }

        $shift->punchWindows()->whereNotIn('event_type', $seenTypes)->delete();
    }

    private function timePlusMinutes(string $time, int $minutes): string
    {
        return now()->setTimeFromTimeString(substr($time, 0, 5))->addMinutes($minutes)->format('H:i');
    }

    private function timeMinusMinutes(string $time, int $minutes): string
    {
        return now()->setTimeFromTimeString(substr($time, 0, 5))->subMinutes($minutes)->format('H:i');
    }

    private function minutesBetween(string $from, string $to): int
    {
        $fromTime = now()->setTimeFromTimeString(substr($from, 0, 5));
        $toTime = now()->setTimeFromTimeString(substr($to, 0, 5));

        if ($toTime < $fromTime) {
            $toTime = $toTime->addDay();
        }

        return (int) $fromTime->diffInMinutes($toTime);
    }
}
