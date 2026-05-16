<?php

namespace App\Modules\People\Attendance\Services;

use App\Modules\People\Attendance\Models\AttendancePunchWindow;
use App\Modules\People\Attendance\Models\AttendanceShiftTemplate;

class ShiftTemplateSerializer
{
    public const SCHEMA = 'belimbing.attendance.shift-template.v1';

    /**
     * @return array<string, mixed>
     */
    public function fromShiftTemplate(AttendanceShiftTemplate $shift): array
    {
        $shift->loadMissing('punchWindows');
        $stored = is_array($shift->break_windows) ? $shift->break_windows : [];
        $in = $shift->punchWindows->firstWhere('event_type', AttendancePunchWindow::TYPE_IN);
        $out = $shift->punchWindows->firstWhere('event_type', AttendancePunchWindow::TYPE_OUT);

        $breakWindows = [];
        foreach ($stored as $break) {
            if (! is_array($break)) {
                continue;
            }
            $breakWindows[] = [
                'starts_at' => substr((string) ($break['starts_at'] ?? ''), 0, 5),
                'ends_at' => substr((string) ($break['ends_at'] ?? ''), 0, 5),
                'label' => $break['label'] ?? 'Break',
                'paid' => (bool) ($break['paid'] ?? false),
            ];
        }

        return [
            'schema' => self::SCHEMA,
            'code' => str($shift->code)->upper()->toString(),
            'name' => $shift->name,
            'summary' => __('Downloaded from Shift Builder.'),
            'best_for' => __('Use as a reviewed starting point for similar rosters.'),
            'starts_at' => substr((string) $shift->starts_at, 0, 5),
            'ends_at' => substr((string) $shift->ends_at, 0, 5),
            'expected_work_minutes' => (int) $shift->expected_work_minutes,
            'break_windows' => $breakWindows,
            'punch_windows' => [
                'in' => [
                    'before_minutes' => $in === null ? 0 : $this->minutesBetween((string) $in->earliest_at, (string) $shift->starts_at),
                    'after_minutes' => $in === null ? 0 : $this->minutesBetween((string) $shift->starts_at, (string) $in->latest_at),
                ],
                'out' => [
                    'before_minutes' => $out === null ? 0 : $this->minutesBetween((string) $out->earliest_at, (string) $shift->ends_at),
                    'after_minutes' => $out === null ? 0 : $this->minutesBetween((string) $shift->ends_at, (string) $out->latest_at),
                ],
            ],
            'cross_midnight_attribution' => $shift->cross_midnight_attribution,
        ];
    }

    public function toJson(array $template): string
    {
        return (string) json_encode($template, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
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
