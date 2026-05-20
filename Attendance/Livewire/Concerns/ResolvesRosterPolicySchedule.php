<?php

namespace App\Modules\People\Attendance\Livewire\Concerns;

use App\Modules\People\Attendance\Models\AttendancePolicyGroup;
use App\Modules\People\Attendance\Models\AttendanceRosterAssignment;
use App\Modules\People\Attendance\Models\AttendanceRosterPattern;
use App\Modules\People\Attendance\Models\AttendanceShiftTemplate;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

trait ResolvesRosterPolicySchedule
{
    /**
     * @return array<string, mixed>|null
     */
    private function exceptionForGridDate(AttendanceRosterAssignment $assignment, string $date): ?array
    {
        foreach ($assignment->exceptions ?? [] as $exception) {
            if (is_array($exception) && ($exception['date'] ?? null) === $date) {
                return $exception;
            }
        }

        return null;
    }

    private function patternShiftCodeForGrid(AttendanceRosterAssignment $assignment, string $date): ?string
    {
        $pattern = $assignment->rosterPattern;
        if (! $pattern instanceof AttendanceRosterPattern) {
            return null;
        }

        $definition = $pattern->pattern_definition ?? [];

        $shiftCode = match ($pattern->pattern_type) {
            AttendanceRosterPattern::TYPE_FIXED_WEEKLY => $definition['weekdays'][strtolower(CarbonImmutable::parse($date)->englishDayOfWeek)]['shift_code'] ?? null,
            AttendanceRosterPattern::TYPE_ROTATING => $this->rotatingPatternShiftCodeForGrid($definition, (string) $assignment->effective_from, $date),
            default => null,
        };

        return is_string($shiftCode) && $shiftCode !== '' ? $shiftCode : null;
    }

    /**
     * @param  array<string, mixed>  $definition
     */
    private function rotatingPatternShiftCodeForGrid(array $definition, string $effectiveFrom, string $date): ?string
    {
        $cycleDays = max(1, (int) ($definition['cycle_days'] ?? 1));
        $offset = CarbonImmutable::parse($effectiveFrom)->diffInDays(CarbonImmutable::parse($date)) % $cycleDays;

        foreach ($definition['days'] ?? [] as $day) {
            if ((int) ($day['offset'] ?? -1) === $offset && is_string($day['shift_code'] ?? null)) {
                return $day['shift_code'];
            }
        }

        return null;
    }

    private function selectedShiftTemplateCode(): ?string
    {
        if (filter_var($this->rosterShiftTemplateId, FILTER_VALIDATE_INT) === false) {
            return null;
        }

        return AttendanceShiftTemplate::query()
            ->where('company_id', $this->companyId())
            ->whereKey((int) $this->rosterShiftTemplateId)
            ->value('code');
    }

    private function selectedPolicyGroupCode(): ?string
    {
        if (filter_var($this->rosterPolicyGroupId, FILTER_VALIDATE_INT) === false) {
            return null;
        }

        return AttendancePolicyGroup::query()
            ->where('company_id', $this->companyId())
            ->whereKey((int) $this->rosterPolicyGroupId)
            ->value('code');
    }

    private function activeShiftTemplateForDate(mixed $shiftTemplateId, string $date): ?AttendanceShiftTemplate
    {
        if (filter_var($shiftTemplateId, FILTER_VALIDATE_INT) === false) {
            return null;
        }

        return AttendanceShiftTemplate::query()
            ->where('company_id', $this->companyId())
            ->whereKey((int) $shiftTemplateId)
            ->where('status', AttendanceShiftTemplate::STATUS_ACTIVE)
            ->whereDate('effective_from', '<=', $date)
            ->where(function ($query) use ($date): void {
                $query->whereNull('effective_to')
                    ->orWhereDate('effective_to', '>=', $date);
            })
            ->first();
    }

    private function activePolicyGroupForDate(mixed $policyGroupId, string $date): ?AttendancePolicyGroup
    {
        if (filter_var($policyGroupId, FILTER_VALIDATE_INT) === false) {
            return null;
        }

        return AttendancePolicyGroup::query()
            ->where('company_id', $this->companyId())
            ->whereKey((int) $policyGroupId)
            ->where('status', AttendancePolicyGroup::STATUS_ACTIVE)
            ->whereDate('effective_from', '<=', $date)
            ->where(function ($query) use ($date): void {
                $query->whereNull('effective_to')
                    ->orWhereDate('effective_to', '>=', $date);
            })
            ->first();
    }

    private function dateWithinDraftRange(string $date): bool
    {
        $start = $this->safeGridStartDate()->toDateString();
        $end = $this->safeGridEndDate(CarbonImmutable::parse($start))->toDateString();

        return $date >= $start && $date <= $end;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function rosterCoverageRows(): array
    {
        $days = $this->rosterGridDays();
        $rows = [];
        $required = max(0, (int) $this->rosterRequiredPerShift);

        foreach ($days as $day) {
            $assigned = AttendanceRosterAssignment::query()
                ->where('company_id', $this->companyId())
                ->whereDate('effective_from', '<=', $day['date'])
                ->where(function ($query) use ($day): void {
                    $query->whereNull('effective_to')
                        ->orWhereDate('effective_to', '>=', $day['date']);
                })
                ->with('shiftTemplate')
                ->get()
                ->groupBy(fn (AttendanceRosterAssignment $assignment): string => $assignment->shiftTemplate?->code ?? '-');

            foreach ($assigned as $shiftCode => $shiftAssignments) {
                $count = $shiftAssignments->count();
                $rows[] = [
                    'date' => $day['date'],
                    'shift' => $shiftCode,
                    'assigned' => $count,
                    'required' => $required,
                    'shortage' => $required > 0 ? max(0, $required - $count) : 0,
                    'surplus' => $required > 0 ? max(0, $count - $required) : 0,
                    'warnings' => $required > 0 && $count < $required ? 1 : 0,
                ];
            }
        }

        return $rows;
    }

    /**
     * Pivot the flat coverage rows into a date × shift heatmap matrix.
     *
     * @param  list<array{date: string, shift: string, assigned: int, required: int, shortage: int, surplus: int, warnings: int}>  $rows
     * @return array{
     *     shifts: list<string>,
     *     dates: list<string>,
     *     cells: array<string, array<string, array{assigned: int, required: int, shortage: int, surplus: int, severity: string}|null>>,
     * }
     */
    private function rosterCoverageMatrix(array $rows): array
    {
        $shifts = [];
        $dates = [];
        $cells = [];

        foreach ($rows as $row) {
            $shift = (string) $row['shift'];
            $date = (string) $row['date'];

            if (! in_array($shift, $shifts, true)) {
                $shifts[] = $shift;
            }
            if (! in_array($date, $dates, true)) {
                $dates[] = $date;
            }

            $severity = 'neutral';
            if (($row['required'] ?? 0) > 0) {
                if (($row['shortage'] ?? 0) > 0) {
                    $severity = 'shortage';
                } elseif (($row['surplus'] ?? 0) > 0) {
                    $severity = 'surplus';
                } else {
                    $severity = 'met';
                }
            }

            $cells[$shift][$date] = [
                'assigned' => (int) ($row['assigned'] ?? 0),
                'required' => (int) ($row['required'] ?? 0),
                'shortage' => (int) ($row['shortage'] ?? 0),
                'surplus' => (int) ($row['surplus'] ?? 0),
                'severity' => $severity,
            ];
        }

        sort($shifts);
        sort($dates);

        return ['shifts' => $shifts, 'dates' => $dates, 'cells' => $cells];
    }

    /**
     * @return list<array{severity: string, code: string, message: string}>
     */
    private function rosterValidationFindings(): array
    {
        $findings = [];
        $employeeIds = $this->selectedRosterEmployeeIds();

        if ($employeeIds === []) {
            $findings[] = [
                'severity' => 'warning',
                'code' => 'no_selected_employees',
                'message' => __('No employees are selected for the draft assignment.'),
            ];
        }

        if ($this->rosterShiftTemplateId === '' || $this->rosterPolicyGroupId === '') {
            $findings[] = [
                'severity' => 'error',
                'code' => 'missing_shift_or_policy',
                'message' => __('Choose both a shift and a policy group before publishing.'),
            ];
        }

        foreach ($employeeIds as $employeeId) {
            if ($this->hasRosterOverlap($employeeId, $this->safeGridStartDate()->toDateString(), $this->safeGridEndDate($this->safeGridStartDate())->toDateString())) {
                $findings[] = [
                    'severity' => 'warning',
                    'code' => 'overlap_existing_roster',
                    'message' => __('Employee :id has an existing roster in the selected date range.', ['id' => $employeeId]),
                ];
            }
        }

        foreach ($this->rosterCoverageRows() as $row) {
            if (($row['shortage'] ?? 0) > 0) {
                $findings[] = [
                    'severity' => 'warning',
                    'code' => 'coverage_shortage',
                    'message' => __(':date :shift is short by :count.', ['date' => $row['date'], 'shift' => $row['shift'], 'count' => $row['shortage']]),
                ];
            }
        }

        return $findings;
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function rosterTemplates(): Collection
    {
        $templates = collect();
        $officeShift = AttendanceShiftTemplate::query()
            ->where('company_id', $this->companyId())
            ->where(function (Builder $query): void {
                $query->where('code', 'like', '%OFFICE%')
                    ->orWhere('code', 'like', '%DAY%');
            })
            ->orderBy('code')
            ->first();
        $rotatingPattern = AttendanceRosterPattern::query()
            ->where('company_id', $this->companyId())
            ->where('pattern_type', AttendanceRosterPattern::TYPE_ROTATING)
            ->orderBy('code')
            ->first();

        if ($officeShift instanceof AttendanceShiftTemplate) {
            $templates->push([
                'key' => 'office_weekday',
                'name' => __('Office weekday starter'),
                'shift_id' => $officeShift->id,
                'pattern_id' => null,
            ]);
        }

        if ($rotatingPattern instanceof AttendanceRosterPattern) {
            $templates->push([
                'key' => 'production_rotation',
                'name' => __('Production rotation starter'),
                'shift_id' => AttendanceShiftTemplate::query()->where('company_id', $this->companyId())->orderBy('code')->value('id'),
                'pattern_id' => $rotatingPattern->id,
            ]);
        }

        return $templates;
    }

    private function assignmentForEmployeeDate(int $employeeId, string $date): ?AttendanceRosterAssignment
    {
        return AttendanceRosterAssignment::query()
            ->where('company_id', $this->companyId())
            ->where('employee_id', $employeeId)
            ->whereDate('effective_from', '<=', $date)
            ->where(function ($query) use ($date): void {
                $query->whereNull('effective_to')
                    ->orWhereDate('effective_to', '>=', $date);
            })
            ->latest('effective_from')
            ->first();
    }
}
