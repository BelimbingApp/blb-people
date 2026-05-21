<?php

namespace App\Modules\People\Attendance\Livewire\Concerns;

use App\Modules\Core\Employee\Models\Employee;
use App\Modules\People\Attendance\Models\AttendancePolicyGroup;
use App\Modules\People\Attendance\Models\AttendanceRosterAssignment;
use App\Modules\People\Attendance\Models\AttendanceRosterLock;
use App\Modules\People\Attendance\Models\AttendanceRosterPattern;
use App\Modules\People\Attendance\Models\AttendanceShiftTemplate;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

trait ManagesRosterOperations
{
    public function copyPreviousPeriod(): void
    {
        if (! $this->ensureSchemaReady()) {
            return;
        }

        $this->authorizeAttendance('people.attendance.manage');

        $employeeIds = $this->selectedRosterEmployeeIds();
        if ($employeeIds === []) {
            $this->addError('selectedRosterEmployeeIds', __('Select employees before copying a previous period.'));

            return;
        }

        $start = $this->safeGridStartDate();
        $end = $this->safeGridEndDate($start);
        $days = $start->diffInDays($end) + 1;
        $previousStart = $start->subDays($days);
        $previousEnd = $end->subDays($days);
        $created = 0;
        $createdIds = [];

        $previousAssignments = AttendanceRosterAssignment::query()
            ->where('company_id', $this->companyId())
            ->whereIn('employee_id', $employeeIds)
            ->whereDate('effective_from', '<=', $previousEnd->toDateString())
            ->where(function ($query) use ($previousStart): void {
                $query->whereNull('effective_to')
                    ->orWhereDate('effective_to', '>=', $previousStart->toDateString());
            })
            ->with(['shiftTemplate', 'policyGroup', 'rosterPattern'])
            ->get();

        foreach ($previousAssignments as $assignment) {
            $newFrom = CarbonImmutable::parse((string) $assignment->effective_from)->addDays($days)->toDateString();
            $newTo = $assignment->effective_to === null ? null : CarbonImmutable::parse((string) $assignment->effective_to)->addDays($days)->toDateString();

            if ($this->hasRosterOverlap((int) $assignment->employee_id, $newFrom, $newTo)) {
                continue;
            }

            $copy = AttendanceRosterAssignment::query()->create([
                'company_id' => $this->companyId(),
                'employee_id' => $assignment->employee_id,
                'attendance_roster_pattern_id' => $assignment->attendance_roster_pattern_id,
                'attendance_shift_template_id' => $assignment->attendance_shift_template_id,
                'attendance_policy_group_id' => $assignment->attendance_policy_group_id,
                'effective_from' => $newFrom,
                'effective_to' => $newTo,

                'lock_state' => 'open',
                'revision' => ((int) $assignment->revision) + 1,
                'exceptions' => $assignment->exceptions ?? [],
                'metadata' => [
                    ...($assignment->metadata ?? []),
                    'created_from' => 'attendance_roster_copy_previous_period',
                    'copied_from_assignment_id' => $assignment->id,
                ],
            ]);

            $createdIds[] = $copy->id;
            $created++;
        }

        $this->lastDraftAssignmentIds = $createdIds;
        session()->flash('success', trans_choice('Copied :count roster assignment from the previous period.|Copied :count roster assignments from the previous period.', $created, ['count' => $created]));
    }

    public function saveCellOverride(int $employeeId, string $date, mixed $shiftTemplateId = null, mixed $policyGroupId = null, string $note = '', string $job = ''): void
    {
        if (! $this->ensureSchemaReady()) {
            return;
        }

        $this->authorizeAttendance('people.attendance.manage');

        if ($this->isDateLocked($date)) {
            session()->flash('error', __('This date is in a locked roster period and cannot be edited.'));

            return;
        }

        $resolvedShiftId = $shiftTemplateId !== null && $shiftTemplateId !== ''
            ? $shiftTemplateId
            : $this->rosterShiftTemplateId;
        $resolvedPolicyId = $policyGroupId !== null && $policyGroupId !== ''
            ? $policyGroupId
            : $this->rosterPolicyGroupId;

        $employee = Employee::query()
            ->where('company_id', $this->companyId())
            ->whereKey($employeeId)
            ->first();
        $shiftTemplate = $this->activeShiftTemplateForDate($resolvedShiftId, $date);
        $policyGroup = $this->activePolicyGroupForDate($resolvedPolicyId, $date);

        if (! $employee instanceof Employee || ! $shiftTemplate instanceof AttendanceShiftTemplate || ! $policyGroup instanceof AttendancePolicyGroup) {
            session()->flash('error', __('Pick a shift and a policy before applying the override.'));

            return;
        }

        $assignment = $this->assignmentForEmployeeDate($employeeId, $date);
        $meta = array_filter(['note' => $note, 'job' => $job], fn (string $v): bool => $v !== '');

        if ($assignment instanceof AttendanceRosterAssignment) {
            $this->appendExceptionOverride($assignment, $date, (int) $shiftTemplate->id, (int) $policyGroup->id, 'cell_override', $meta);
            session()->flash('success', __('Roster cell override saved.'));

            return;
        }

        $created = AttendanceRosterAssignment::query()->create([
            'company_id' => $this->companyId(),
            'employee_id' => $employeeId,
            'attendance_shift_template_id' => (int) $shiftTemplate->id,
            'attendance_policy_group_id' => (int) $policyGroup->id,
            'effective_from' => $date,
            'effective_to' => $date,
            'lock_state' => 'open',
            'revision' => 1,
            'exceptions' => [],
            'metadata' => ['created_from' => 'attendance_roster_cell_override', ...$meta],
        ]);

        $this->lastDraftAssignmentIds = [$created->id];
        session()->flash('success', __('Roster cell override saved.'));
    }

    /**
     * @param  list<array{employee_id: int, date: string, shift_template_id: int, policy_group_id: int}>  $overrides
     *
     * Zero shift_template_id or policy_group_id means "clear the cell override".
     * Validated entries must fall within the current grid period and belong to
     * the current company. All writes are wrapped in one transaction.
     */
    public function saveCellOverrides(array $overrides, string $note = '', string $job = ''): void
    {
        if (! $this->ensureSchemaReady()) {
            return;
        }

        $this->authorizeAttendance('people.attendance.manage');

        $companyId = $this->companyId();
        $gridStart = $this->gridPeriodStart()->toDateString();
        $gridEnd = $this->gridPeriodEnd()->toDateString();

        $candidateEmpIds = [];
        $candidateDates = [];
        foreach ($overrides as $o) {
            if (! is_array($o)) {
                continue;
            }
            $empId = (int) ($o['employee_id'] ?? 0);
            $date = (string) ($o['date'] ?? '');
            if ($empId > 0 && $date !== '' && $date >= $gridStart && $date <= $gridEnd) {
                $candidateEmpIds[] = $empId;
                $candidateDates[] = $date;
            }
        }

        $validEmpIds = $candidateEmpIds !== []
            ? Employee::query()->where('company_id', $companyId)->whereKey(array_unique($candidateEmpIds))->pluck('id')->all()
            : [];
        $lockedDateSet = $candidateDates !== []
            ? $this->lockedDateSetForDates(array_unique($candidateDates))
            : [];

        $valid = [];
        foreach ($overrides as $o) {
            if (! is_array($o)) {
                continue;
            }
            $empId = (int) ($o['employee_id'] ?? 0);
            $date = (string) ($o['date'] ?? '');
            if ($empId <= 0 || $date === '' || $date < $gridStart || $date > $gridEnd) {
                continue;
            }
            if (isset($lockedDateSet[$date]) || ! in_array($empId, $validEmpIds, true)) {
                continue;
            }
            $valid[] = [
                'employee_id' => $empId,
                'date' => $date,
                'shift_template_id' => (int) ($o['shift_template_id'] ?? 0),
                'policy_group_id' => (int) ($o['policy_group_id'] ?? 0),
            ];
        }

        if ($valid === []) {
            return;
        }

        $createdIds = [];
        $savedCount = 0;
        $meta = array_filter(['note' => $note, 'job' => $job], fn (string $v): bool => $v !== '');

        DB::transaction(function () use ($valid, $companyId, $meta, &$createdIds, &$savedCount): void {
            foreach ($valid as $o) {
                $isClear = $o['shift_template_id'] === 0 || $o['policy_group_id'] === 0;
                $assignment = $this->assignmentForEmployeeDate($o['employee_id'], $o['date']);

                if ($isClear) {
                    if (! $assignment instanceof AttendanceRosterAssignment) {
                        continue;
                    }
                    $from = $assignment->effective_from?->toDateString();
                    $to = $assignment->effective_to?->toDateString();
                    if ($from === $o['date'] && ($to === null || $to === $o['date'])) {
                        $assignment->delete();
                    } else {
                        $this->removeExceptionOverride($assignment, $o['date']);
                    }
                    $savedCount++;

                    continue;
                }

                $shift = $this->activeShiftTemplateForDate($o['shift_template_id'], $o['date']);
                $policy = $this->activePolicyGroupForDate($o['policy_group_id'], $o['date']);
                if (! $shift instanceof AttendanceShiftTemplate || ! $policy instanceof AttendancePolicyGroup) {
                    continue;
                }

                if ($assignment instanceof AttendanceRosterAssignment) {
                    $this->appendExceptionOverride($assignment, $o['date'], (int) $shift->id, (int) $policy->id, 'cell_override_batch', $meta);
                } else {
                    $created = AttendanceRosterAssignment::query()->create([
                        'company_id' => $companyId,
                        'employee_id' => $o['employee_id'],
                        'attendance_shift_template_id' => (int) $shift->id,
                        'attendance_policy_group_id' => (int) $policy->id,
                        'effective_from' => $o['date'],
                        'effective_to' => $o['date'],

                        'lock_state' => 'open',
                        'revision' => 1,
                        'exceptions' => [],
                        'metadata' => ['created_from' => 'attendance_roster_cell_override_batch', ...$meta],
                    ]);
                    $createdIds[] = $created->id;
                }
                $savedCount++;
            }
        });

        $this->lastDraftAssignmentIds = [...$this->lastDraftAssignmentIds, ...$createdIds];

        if ($savedCount > 0) {
            session()->flash('success', trans_choice(
                'Cell override saved.|:count cell overrides saved.',
                $savedCount,
                ['count' => $savedCount],
            ));
        }
    }

    public function swapRosterCells(): void
    {
        if (! $this->ensureSchemaReady()) {
            return;
        }

        $this->authorizeAttendance('people.attendance.manage');

        if (filter_var($this->swapFromEmployeeId, FILTER_VALIDATE_INT) === false || filter_var($this->swapToEmployeeId, FILTER_VALIDATE_INT) === false || trim($this->swapDate) === '') {
            $this->addError('swapDate', __('Choose two employees and a date to swap.'));

            return;
        }

        if ($this->isDateLocked($this->swapDate)) {
            $this->addError('swapDate', __('This date is in a locked roster period and cannot be swapped.'));

            return;
        }

        $from = $this->assignmentForEmployeeDate((int) $this->swapFromEmployeeId, $this->swapDate);
        $to = $this->assignmentForEmployeeDate((int) $this->swapToEmployeeId, $this->swapDate);

        if (! $from instanceof AttendanceRosterAssignment || ! $to instanceof AttendanceRosterAssignment) {
            $this->addError('swapDate', __('Both employees need an assignment on the selected date before swapping.'));

            return;
        }

        $this->appendExceptionOverride($from, $this->swapDate, (int) $to->attendance_shift_template_id, (int) $to->attendance_policy_group_id, 'swap');
        $this->appendExceptionOverride($to, $this->swapDate, (int) $from->attendance_shift_template_id, (int) $from->attendance_policy_group_id, 'swap');
        $this->dispatch('close-swap-modal');
        session()->flash('success', __('Roster shift swap saved.'));
    }

    public function importSpreadsheetRosterRows(): void
    {
        if (! $this->ensureSchemaReady()) {
            return;
        }

        $this->authorizeAttendance('people.attendance.manage');

        $parsed = $this->parseSpreadsheetRows();
        if ($parsed['errors'] !== []) {
            foreach ($parsed['errors'] as $error) {
                $this->addError('spreadsheetRosterRows', $error);
            }

            return;
        }

        $createdIds = [];
        $overrides = 0;

        foreach ($parsed['rows'] as $row) {
            $assignment = $this->assignmentForEmployeeDate((int) $row['employee_id'], (string) $row['date']);

            if ($assignment instanceof AttendanceRosterAssignment) {
                $this->appendExceptionOverride($assignment, (string) $row['date'], (int) $row['shift_id'], (int) $row['policy_group_id'], 'spreadsheet_import', [
                    'source_row' => $row['source_row'],
                    'notes' => $row['notes'],
                ]);
                $overrides++;

                continue;
            }

            $created = AttendanceRosterAssignment::query()->create([
                'company_id' => $this->companyId(),
                'employee_id' => $row['employee_id'],
                'attendance_shift_template_id' => $row['shift_id'],
                'attendance_policy_group_id' => $row['policy_group_id'],
                'effective_from' => $row['date'],
                'effective_to' => $row['date'],

                'lock_state' => 'open',
                'revision' => 1,
                'exceptions' => [],
                'metadata' => [
                    'created_from' => 'attendance_roster_spreadsheet_import',
                    'source_row' => $row['source_row'],
                    'notes' => $row['notes'],
                ],
            ]);

            $createdIds[] = $created->id;
        }

        $this->lastDraftAssignmentIds = $createdIds;
        $this->spreadsheetRosterRows = '';
        session()->flash('success', __('Spreadsheet roster import saved :created draft rows and :overrides overrides.', ['created' => count($createdIds), 'overrides' => $overrides]));
    }

    public function undoLastDraftRosterOperation(): void
    {
        if ($this->lastDraftAssignmentIds === []) {
            $this->addError('selectedRosterEmployeeIds', __('There is no draft roster operation to undo.'));

            return;
        }

        $deleted = AttendanceRosterAssignment::query()
            ->where('company_id', $this->companyId())
            ->whereIn('id', $this->lastDraftAssignmentIds)
            ->delete();

        $this->lastDraftAssignmentIds = [];
        session()->flash('success', trans_choice('Undid :count draft roster assignment.|Undid :count draft roster assignments.', $deleted, ['count' => $deleted]));
    }

    public function saveRosterAssignment(): void
    {
        if (! $this->ensureSchemaReady()) {
            return;
        }

        $this->authorizeAttendance('people.attendance.manage');

        $companyId = $this->companyId();
        $validated = $this->validate([
            'rosterEmployeeId' => ['nullable', 'integer', Rule::exists(Employee::class, 'id')->where('company_id', $companyId)],
            'selectedRosterEmployeeIds' => ['array'],
            'selectedRosterEmployeeIds.*' => ['integer', Rule::exists(Employee::class, 'id')->where('company_id', $companyId)],
            'rosterPatternId' => ['nullable', 'integer', Rule::exists(AttendanceRosterPattern::class, 'id')->where('company_id', $companyId)],
            'rosterShiftTemplateId' => ['required', 'integer', Rule::exists(AttendanceShiftTemplate::class, 'id')->where('company_id', $companyId)],
            'rosterPolicyGroupId' => ['required', 'integer', Rule::exists(AttendancePolicyGroup::class, 'id')->where('company_id', $companyId)],
            'rosterEffectiveFrom' => ['required', 'date'],
            'rosterEffectiveTo' => ['nullable', 'date', 'after_or_equal:rosterEffectiveFrom'],
        ]);

        $employeeIds = $this->selectedRosterEmployeeIds();

        if ($employeeIds === []) {
            $this->addError('selectedRosterEmployeeIds', __('Select at least one employee to roster.'));

            return;
        }

        $effectiveTo = $this->blankToNull($validated['rosterEffectiveTo'] ?? null);
        $created = 0;
        $skipped = 0;
        $createdIds = [];

        foreach ($employeeIds as $employeeId) {
            if ($this->hasRosterOverlap($employeeId, $validated['rosterEffectiveFrom'], $effectiveTo)) {
                $skipped++;

                continue;
            }

            $assignment = AttendanceRosterAssignment::query()->create([
                'company_id' => $companyId,
                'employee_id' => $employeeId,
                'attendance_roster_pattern_id' => $this->blankToNull($validated['rosterPatternId'] ?? null),
                'attendance_shift_template_id' => (int) $validated['rosterShiftTemplateId'],
                'attendance_policy_group_id' => (int) $validated['rosterPolicyGroupId'],
                'effective_from' => $validated['rosterEffectiveFrom'],
                'effective_to' => $effectiveTo,
                'lock_state' => 'open',
                'revision' => 1,
                'exceptions' => [],
                'metadata' => [
                    'created_from' => 'attendance_roster_builder',
                    'selection_mode' => $this->rosterSelectAllFiltered ? 'all_filtered' : 'selected_employees',
                    'filters' => $this->rosterFilters(),
                    ...($this->rosterBulkNote !== '' ? ['note' => $this->rosterBulkNote] : []),
                ],
            ]);

            $createdIds[] = $assignment->id;
            $created++;
        }

        if ($created === 0) {
            $this->addError('rosterEffectiveFrom', __('Every selected employee already has a roster assignment in that date range.'));

            return;
        }

        $this->lastDraftAssignmentIds = $createdIds;
        $this->dispatch('close-bulk-modal');
        $this->resetForm();
        session()->flash('success', trans_choice(
            'Roster assignment saved. :skipped skipped because of existing roster overlaps.|:count roster assignments saved. :skipped skipped because of existing roster overlaps.',
            $created,
            ['count' => $created, 'skipped' => $skipped],
        ));
    }

    public function lockRosterPeriod(string $from, string $to): void
    {
        if (! $this->ensureSchemaReady()) {
            return;
        }

        $this->authorizeAttendance('people.attendance.manage');

        AttendanceRosterLock::query()->updateOrCreate(
            ['company_id' => $this->companyId(), 'period_start' => $from, 'period_end' => $to],
            [
                'locked_by' => (int) auth()->id(),
                'locked_at' => now(),
                'unlocked_at' => null,
                'unlocked_by' => null,
                'unlock_reason' => null,
            ],
        );

        session()->flash('success', __('Roster period locked. Overrides are blocked until unlocked.'));
    }

    public function unlockRosterPeriod(string $from, string $to, string $reason): void
    {
        if (! $this->ensureSchemaReady()) {
            return;
        }

        $this->authorizeAttendance('people.attendance.roster.unlock');

        if (trim($reason) === '') {
            $this->addError('unlockReason', __('An unlock reason is required.'));

            return;
        }

        AttendanceRosterLock::query()
            ->where('company_id', $this->companyId())
            ->where('period_start', $from)
            ->where('period_end', $to)
            ->whereNull('unlocked_at')
            ->update([
                'unlocked_at' => now(),
                'unlocked_by' => (int) auth()->id(),
                'unlock_reason' => trim($reason),
            ]);

        session()->flash('success', __('Roster period unlocked.'));
    }

    public function deleteRosterAssignment(int $assignmentId): void
    {
        if (! $this->ensureSchemaReady()) {
            return;
        }

        $this->authorizeAttendance('people.attendance.manage');

        AttendanceRosterAssignment::query()
            ->where('company_id', $this->companyId())
            ->findOrFail($assignmentId)
            ->delete();

        session()->flash('success', __('Roster assignment deleted.'));
    }

    private function isDateLocked(string $date): bool
    {
        return AttendanceRosterLock::query()
            ->where('company_id', $this->companyId())
            ->where('period_start', '<=', $date)
            ->where('period_end', '>=', $date)
            ->whereNull('unlocked_at')
            ->exists();
    }

    /**
     * Returns a date → true map for the given dates that fall within a locked period.
     * Single query replaces N isDateLocked() calls in batch operations.
     *
     * @param  list<string>  $dates
     * @return array<string, true>
     */
    private function lockedDateSetForDates(array $dates): array
    {
        if ($dates === []) {
            return [];
        }

        $min = min($dates);
        $max = max($dates);

        $locks = AttendanceRosterLock::query()
            ->where('company_id', $this->companyId())
            ->where('period_start', '<=', $max)
            ->where('period_end', '>=', $min)
            ->whereNull('unlocked_at')
            ->get(['period_start', 'period_end']);

        $locked = [];
        foreach ($locks as $lock) {
            foreach ($dates as $date) {
                if ($date >= $lock->period_start && $date <= $lock->period_end) {
                    $locked[$date] = true;
                }
            }
        }

        return $locked;
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function appendExceptionOverride(AttendanceRosterAssignment $assignment, string $date, int $shiftTemplateId, int $policyGroupId, string $source, array $metadata = []): void
    {
        $exceptions = collect($assignment->exceptions ?? [])
            ->reject(fn (mixed $row): bool => is_array($row) && ($row['date'] ?? null) === $date)
            ->values()
            ->all();

        $exceptions[] = [
            'date' => $date,
            'attendance_shift_template_id' => $shiftTemplateId,
            'attendance_policy_group_id' => $policyGroupId,
            'source' => $source,
            'metadata' => $metadata,
        ];

        $assignment->forceFill([
            'exceptions' => $exceptions,
            'revision' => ((int) $assignment->revision) + 1,
            'metadata' => [
                ...($assignment->metadata ?? []),
                'last_exception_source' => $source,
                'last_exception_at' => now()->toIso8601String(),
            ],
        ])->save();

        if ($assignment->employee_id !== null) {
            $this->resetAcknowledgmentForEmployeeDate((int) $assignment->employee_id, $date);
        }
    }

    private function removeExceptionOverride(AttendanceRosterAssignment $assignment, string $date): void
    {
        $exceptions = collect($assignment->exceptions ?? [])
            ->reject(fn (mixed $row): bool => is_array($row) && ($row['date'] ?? null) === $date)
            ->values()
            ->all();

        $assignment->forceFill([
            'exceptions' => $exceptions,
            'revision' => ((int) $assignment->revision) + 1,
        ])->save();
    }

    /**
     * @return array{rows: list<array<string, mixed>>, errors: list<string>}
     */
    private function parseSpreadsheetRows(): array
    {
        $text = trim($this->spreadsheetRosterRows);
        if ($text === '') {
            return ['rows' => [], 'errors' => []];
        }

        $rows = [];
        $errors = [];
        $lines = preg_split('/\R/', $text) ?: [];

        foreach ($lines as $index => $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }

            $parts = str_getcsv($line, str_contains($line, "\t") ? "\t" : ',');
            if (strtolower((string) ($parts[0] ?? '')) === 'employee_number') {
                continue;
            }

            [$employeeNumber, $date, $shiftCode, $policyCode, $notes] = array_pad($parts, 5, '');
            $employee = Employee::query()
                ->where('company_id', $this->companyId())
                ->where('employee_number', trim((string) $employeeNumber))
                ->first();
            $shift = AttendanceShiftTemplate::query()
                ->where('company_id', $this->companyId())
                ->where('code', trim((string) $shiftCode))
                ->first();
            $policy = AttendancePolicyGroup::query()
                ->where('company_id', $this->companyId())
                ->where('code', trim((string) $policyCode))
                ->first();

            try {
                $parsedDate = CarbonImmutable::parse((string) $date)->toDateString();
            } catch (\Throwable) {
                $parsedDate = null;
            }

            if (! $employee instanceof Employee || ! $shift instanceof AttendanceShiftTemplate || ! $policy instanceof AttendancePolicyGroup || $parsedDate === null) {
                $errors[] = __('Row :row has an unknown employee, shift, policy, or date.', ['row' => $index + 1]);

                continue;
            }

            $rows[] = [
                'employee_id' => $employee->id,
                'employee_number' => $employee->employee_number,
                'date' => $parsedDate,
                'shift_id' => $shift->id,
                'shift_code' => $shift->code,
                'policy_group_id' => $policy->id,
                'policy_code' => $policy->code,
                'notes' => trim((string) $notes),
                'source_row' => $index + 1,
            ];
        }

        return ['rows' => $rows, 'errors' => $errors];
    }
}
