<?php

namespace App\Modules\People\Attendance\Observers;

use App\Modules\People\Attendance\Models\AttendanceRosterAssignment;
use App\Modules\People\Attendance\Models\AttendanceRosterCellLog;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;

class AttendanceRosterAssignmentObserver
{
    private const MAX_EXPAND_DAYS = 366;

    public function created(AttendanceRosterAssignment $assignment): void
    {
        if ($assignment->employee_id === null) {
            return;
        }

        $this->expandDates(
            $assignment->effective_from?->toDateString() ?? '',
            $assignment->effective_to?->toDateString(),
            function (string $date) use ($assignment): void {
                $this->writeLog($assignment, $date, 'created',
                    null, null,
                    $assignment->attendance_shift_template_id,
                    $assignment->attendance_policy_group_id,
                );
            },
        );
    }

    public function updated(AttendanceRosterAssignment $assignment): void
    {
        if ($assignment->employee_id === null) {
            return;
        }

        $changed = array_keys($assignment->getChanges());

        if (in_array('exceptions', $changed, true)) {
            $this->logExceptionChanges($assignment);

            return;
        }

        // Shift, policy, or date range changed — re-expand the range
        if (array_intersect($changed, ['attendance_shift_template_id', 'attendance_policy_group_id', 'effective_from', 'effective_to']) !== []) {
            $prevShiftId = $assignment->getOriginal('attendance_shift_template_id');
            $prevPolicyId = $assignment->getOriginal('attendance_policy_group_id');

            $this->expandDates(
                $assignment->effective_from?->toDateString() ?? '',
                $assignment->effective_to?->toDateString(),
                function (string $date) use ($assignment, $prevShiftId, $prevPolicyId): void {
                    $this->writeLog($assignment, $date, 'updated',
                        $prevShiftId, $prevPolicyId,
                        $assignment->attendance_shift_template_id,
                        $assignment->attendance_policy_group_id,
                    );
                },
            );
        }
    }

    public function deleted(AttendanceRosterAssignment $assignment): void
    {
        if ($assignment->employee_id === null) {
            return;
        }

        $this->expandDates(
            $assignment->effective_from?->toDateString() ?? '',
            $assignment->effective_to?->toDateString(),
            function (string $date) use ($assignment): void {
                $this->writeLog($assignment, $date, 'deleted',
                    $assignment->attendance_shift_template_id,
                    $assignment->attendance_policy_group_id,
                    null, null,
                );
            },
        );
    }

    private function logExceptionChanges(AttendanceRosterAssignment $assignment): void
    {
        $oldExceptions = $this->parseExceptions($assignment->getOriginal('exceptions'));
        $newExceptions = collect($assignment->exceptions ?? []);

        $allDates = $newExceptions->pluck('date')
            ->merge($oldExceptions->pluck('date'))
            ->unique()
            ->filter(fn (mixed $d): bool => is_string($d) && $d !== '');

        foreach ($allDates as $date) {
            $oldEntry = $oldExceptions->firstWhere('date', $date);
            $newEntry = $newExceptions->firstWhere('date', $date);

            $oldShift = is_array($oldEntry) ? ($oldEntry['attendance_shift_template_id'] ?? null) : null;
            $oldPolicy = is_array($oldEntry) ? ($oldEntry['attendance_policy_group_id'] ?? null) : null;
            $newShift = is_array($newEntry) ? ($newEntry['attendance_shift_template_id'] ?? null) : null;
            $newPolicy = is_array($newEntry) ? ($newEntry['attendance_policy_group_id'] ?? null) : null;

            // Resolve base assignment shift when no exception existed
            $prevShift = $oldEntry !== null ? $oldShift : $assignment->getOriginal('attendance_shift_template_id');
            $prevPolicy = $oldEntry !== null ? $oldPolicy : $assignment->getOriginal('attendance_policy_group_id');

            // Resolve new value: fall back to assignment base when exception removed
            $newShiftFinal = $newEntry !== null ? $newShift : $assignment->attendance_shift_template_id;
            $newPolicyFinal = $newEntry !== null ? $newPolicy : $assignment->attendance_policy_group_id;

            if ($prevShift === $newShiftFinal && $prevPolicy === $newPolicyFinal) {
                continue;
            }

            $this->writeLog($assignment, (string) $date, 'updated',
                $prevShift, $prevPolicy, $newShiftFinal, $newPolicyFinal,
            );
        }
    }

    private function writeLog(
        AttendanceRosterAssignment $assignment,
        string $date,
        string $action,
        mixed $prevShiftId,
        mixed $prevPolicyId,
        mixed $newShiftId,
        mixed $newPolicyId,
    ): void {
        AttendanceRosterCellLog::query()->create([
            'company_id' => $assignment->company_id,
            'assignment_id' => $assignment->id,
            'employee_id' => $assignment->employee_id,
            'date' => $date,
            'changed_by' => Auth::id(),
            'action' => $action,
            'previous_shift_id' => $prevShiftId !== null ? (int) $prevShiftId : null,
            'previous_policy_id' => $prevPolicyId !== null ? (int) $prevPolicyId : null,
            'new_shift_id' => $newShiftId !== null ? (int) $newShiftId : null,
            'new_policy_id' => $newPolicyId !== null ? (int) $newPolicyId : null,
            'changed_at' => now(),
        ]);
    }

    private function expandDates(string $from, ?string $to, callable $callback): void
    {
        if ($from === '') {
            return;
        }

        try {
            $start = CarbonImmutable::parse($from);
        } catch (\Throwable) {
            return;
        }

        $end = $to !== null
            ? CarbonImmutable::parse($to)
            : $start->addDays(self::MAX_EXPAND_DAYS - 1);

        // Cap open-ended assignments to avoid unbounded expansion
        if ($end->diffInDays($start) >= self::MAX_EXPAND_DAYS) {
            $end = $start->addDays(self::MAX_EXPAND_DAYS - 1);
        }

        for ($date = $start; $date->lessThanOrEqualTo($end); $date = $date->addDay()) {
            $callback($date->toDateString());
        }
    }

    private function parseExceptions(mixed $raw): Collection
    {
        if (is_array($raw)) {
            return collect($raw);
        }

        if (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);

            return collect(is_array($decoded) ? $decoded : []);
        }

        return collect();
    }
}
