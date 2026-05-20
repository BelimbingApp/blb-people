<?php

namespace App\Modules\People\Attendance\Livewire\Concerns;

use App\Modules\People\Attendance\Models\AttendanceRosterAcknowledgment;
use Illuminate\Support\Collection;

trait ManagesRosterSelfService
{
    public function acknowledgeSchedule(string $periodStart, string $periodEnd): void
    {
        if (! $this->ensureSchemaReady()) {
            return;
        }

        $employeeId = $this->currentEmployeeId();
        if ($employeeId === null) {
            return;
        }

        AttendanceRosterAcknowledgment::query()->updateOrCreate(
            [
                'company_id' => $this->companyId(),
                'employee_id' => $employeeId,
                'period_start' => $periodStart,
                'period_end' => $periodEnd,
            ],
            [
                'actor_id' => auth()->id(),
                'acknowledged_at' => now(),
            ],
        );

        session()->flash('success', __('Schedule acknowledged.'));
    }

    public function exportRosterCsv(): mixed
    {
        if (! $this->ensureSchemaReady()) {
            return null;
        }

        $this->authorizeAttendance('people.attendance.manage');

        $employees = $this->filteredEmployeesQuery()->orderBy('full_name')->orderBy('id')->get();
        $rows = $this->rosterGridRows($employees);
        $days = $this->rosterGridDays();
        $filename = 'roster-'.$this->gridPeriodStart()->format('Y-m-d').'.csv';

        return response()->streamDownload(function () use ($rows, $days): void {
            $handle = fopen('php://output', 'w');
            if ($handle === false) {
                return;
            }

            $header = ['Employee', 'Department'];
            foreach ($days as $day) {
                $header[] = $day['date'];
            }
            fputcsv($handle, $header);

            foreach ($rows as $row) {
                $line = [
                    $row['employee']->full_name,
                    $row['group'],
                ];
                foreach ($days as $day) {
                    $cell = $row['cells'][$day['date']] ?? null;
                    if ($cell === null || ($cell['state'] ?? 'empty') === 'empty') {
                        $line[] = '';
                    } else {
                        $suffix = $cell['state'] === 'draft' ? ' (draft)' : '';
                        $line[] = ($cell['label'] ?? '').$suffix;
                    }
                }
                fputcsv($handle, $line);
            }

            fclose($handle);
        }, $filename, ['Content-Type' => 'text/csv']);
    }

    private function isMyScheduleMode(): bool
    {
        return ! $this->canAttendance('people.attendance.manage')
            && $this->canAttendance('people.attendance.roster.view')
            && $this->currentEmployeeId() !== null;
    }

    private function acknowledgedForPeriod(string $periodStart, string $periodEnd): bool
    {
        $employeeId = $this->currentEmployeeId();
        if ($employeeId === null) {
            return false;
        }

        return AttendanceRosterAcknowledgment::query()
            ->where('company_id', $this->companyId())
            ->where('employee_id', $employeeId)
            ->where('period_start', $periodStart)
            ->where('period_end', $periodEnd)
            ->exists();
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return array{acknowledged: int, total: int}|null
     */
    private function acknowledgmentCountForPeriod(Collection $rows, string $periodStart, string $periodEnd): ?array
    {
        $employeeIds = $rows->map(fn (array $row): ?int => $row['employee']?->id)->filter()->values()->all();
        if ($employeeIds === []) {
            return null;
        }

        $ackCount = AttendanceRosterAcknowledgment::query()
            ->where('company_id', $this->companyId())
            ->whereIn('employee_id', $employeeIds)
            ->where('period_start', $periodStart)
            ->where('period_end', $periodEnd)
            ->count();

        return ['acknowledged' => $ackCount, 'total' => count($employeeIds)];
    }

    private function resetAcknowledgmentForEmployeeDate(int $employeeId, string $date): void
    {
        AttendanceRosterAcknowledgment::query()
            ->where('company_id', $this->companyId())
            ->where('employee_id', $employeeId)
            ->where('period_start', '<=', $date)
            ->where('period_end', '>=', $date)
            ->delete();
    }
}
