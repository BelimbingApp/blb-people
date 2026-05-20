<?php

namespace App\Modules\People\Attendance\Livewire\Concerns;

use App\Modules\People\Attendance\Models\AttendanceShiftTemplate;

trait ManagesShiftBreaks
{
    public function addShiftBreak(): void
    {
        if (count($this->shiftBreaks) >= 2) {
            return;
        }

        $this->shiftBreaks[] = ['label' => 'Break', 'starts_at' => '', 'ends_at' => '', 'paid' => false];
    }

    public function removeShiftBreak(int $index): void
    {
        unset($this->shiftBreaks[$index]);
        $this->shiftBreaks = array_values($this->shiftBreaks);
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return list<array{starts_at: string, ends_at: string, label: string, paid: bool}>
     */
    private function breakWindows(array $validated): array
    {
        $breaks = [];

        foreach ($validated['shiftBreaks'] ?? [] as $break) {
            if (! is_array($break)) {
                continue;
            }
            $starts = $this->blankToNull($break['starts_at'] ?? null);
            $ends = $this->blankToNull($break['ends_at'] ?? null);
            if ($starts === null || $ends === null) {
                continue;
            }
            $breaks[] = [
                'starts_at' => $starts,
                'ends_at' => $ends,
                'label' => $this->blankToNull($break['label'] ?? null) ?? 'Break',
                'paid' => (bool) ($break['paid'] ?? false),
            ];
        }

        return $breaks;
    }

    private function loadBreakWindows(AttendanceShiftTemplate $shift): void
    {
        $stored = is_array($shift->break_windows) ? $shift->break_windows : [];
        $this->shiftBreaks = $this->normalizeBreaksForState($stored);
    }

    /**
     * @param  array<int, mixed>  $stored
     * @param  array{starts_at?: string, ends_at?: string}  $fallback  legacy single-break fallback for imported templates
     * @return list<array{label: string, starts_at: string, ends_at: string, paid: bool}>
     */
    private function normalizeBreaksForState(array $stored, array $fallback = []): array
    {
        $breaks = [];
        foreach ($stored as $break) {
            if (! is_array($break)) {
                continue;
            }
            $breaks[] = [
                'label' => (string) ($break['label'] ?? 'Break'),
                'starts_at' => substr((string) ($break['starts_at'] ?? ''), 0, 5),
                'ends_at' => substr((string) ($break['ends_at'] ?? ''), 0, 5),
                'paid' => (bool) ($break['paid'] ?? false),
            ];
        }

        if ($breaks === [] && (($fallback['starts_at'] ?? '') !== '' || ($fallback['ends_at'] ?? '') !== '')) {
            $breaks[] = [
                'label' => 'Break',
                'starts_at' => substr((string) ($fallback['starts_at'] ?? ''), 0, 5),
                'ends_at' => substr((string) ($fallback['ends_at'] ?? ''), 0, 5),
                'paid' => false,
            ];
        }

        return array_slice($breaks, 0, 2);
    }
}
