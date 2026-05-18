<?php

namespace App\Modules\People\Attendance\Livewire;

use App\Modules\Core\Company\Models\Department;
use App\Modules\Core\Employee\Models\Employee;
use App\Modules\People\Attendance\Livewire\Concerns\InteractsWithAttendanceScreen;
use App\Modules\People\Attendance\Models\AttendanceDay;
use App\Modules\People\Attendance\Models\AttendancePolicyGroup;
use App\Modules\People\Attendance\Models\AttendanceRosterAssignment;
use App\Modules\People\Attendance\Models\AttendanceRosterPattern;
use App\Modules\People\Attendance\Models\AttendanceShiftTemplate;
use App\Modules\People\Attendance\Services\AttendanceCalendarResolver;
use App\Modules\People\Attendance\Support\DayTypeVocabulary;
use App\Modules\People\Settings\Models\PeopleNotificationDeliveryLog;
use App\Modules\People\Settings\Models\PeopleReferenceEntry;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Livewire\Component;
use Livewire\WithPagination;

class Rosters extends Component
{
    use InteractsWithAttendanceScreen;
    use WithPagination;

    public string $mode = 'list';

    public string $rosterSearch = '';

    public string $rosterDepartmentId = '';

    public string $rosterSupervisorId = '';

    public string $rosterOrganizationUnitId = '';

    public string $rosterCostCenterId = '';

    public string $rosterWorkforceClassId = '';

    public string $rosterEmploymentGroupId = '';

    public string $rosterWorkCalendarId = '';

    public string $rosterPayRateType = '';

    public string $rosterEmployeeStatus = 'active';

    public bool $rosterSelectAllFiltered = false;

    /**
     * @var list<string>
     */
    public array $selectedRosterEmployeeIds = [];

    public string $rosterEmployeeId = '';

    /**
     * When non-empty, `saveRosterAssignment()` updates the named assignment
     * in place instead of creating new rows for the selected population.
     * Set by `editRosterAssignment($id)`; cleared by `resetForm()`.
     */
    public string $editingRosterAssignmentId = '';

    public string $rosterPatternId = '';

    public string $rosterShiftTemplateId = '';

    public string $rosterPolicyGroupId = '';

    public string $rosterEffectiveFrom = '';

    public string $rosterEffectiveTo = '';

    public string $rosterPublishState = 'draft';

    public string $rosterRevisionNote = '';

    public bool $rosterValidationRan = false;

    public bool $rosterWarningsAccepted = false;

    public string $rosterRequiredPerShift = '';

    public string $rosterTemplateKey = '';

    public string $swapFromEmployeeId = '';

    public string $swapToEmployeeId = '';

    public string $swapDate = '';

    public string $spreadsheetRosterRows = '';

    /**
     * Monday of the week being browsed in list mode. Empty defaults to today's
     * Monday. Drives the calendar grid that opens the page; isolated from
     * `rosterEffectiveFrom/To` so navigating the list doesn't mutate the form.
     */
    public string $listWeekAnchor = '';

    /**
     * Zoom level for the list-mode calendar. `week` shows Mon-Sun for the
     * anchor; `month` shows the full month containing the anchor. Period
     * navigation buttons step a week or a month at a time accordingly.
     */
    public string $listScope = 'week';

    /**
     * @var list<int>
     */
    public array $lastDraftAssignmentIds = [];

    public function mount(): void
    {
        $this->rosterEffectiveFrom = now()->toDateString();
    }

    public function updated(string $property): void
    {
        if (in_array($property, $this->rosterFilterProperties(), true)) {
            $this->resetPage();
            $this->rosterSelectAllFiltered = false;
        }
    }

    public function selectVisibleRosterEmployees(): void
    {
        if (! $this->ensureSchemaReady()) {
            return;
        }

        $ids = $this->filteredEmployeesQuery()
            ->orderBy('employees.full_name')
            ->orderBy('employees.id')
            ->forPage($this->getPage(), 25)
            ->pluck('employees.id')
            ->map(fn (int $id): string => (string) $id)
            ->all();

        $this->selectedRosterEmployeeIds = array_values(array_unique([
            ...$this->selectedRosterEmployeeIds,
            ...$ids,
        ]));
    }

    public function selectAllFilteredRosterEmployees(): void
    {
        if (! $this->ensureSchemaReady()) {
            return;
        }

        $this->rosterSelectAllFiltered = true;
        $this->selectedRosterEmployeeIds = [];
    }

    public function clearRosterSelection(): void
    {
        $this->rosterSelectAllFiltered = false;
        $this->selectedRosterEmployeeIds = [];
        $this->rosterEmployeeId = '';
    }

    public function clearRosterFilters(): void
    {
        $this->reset($this->rosterFilterProperties());
        $this->rosterEmployeeStatus = 'active';
        $this->clearRosterSelection();
        $this->resetPage();
    }

    public function validateRosterDraft(): void
    {
        $this->rosterValidationRan = true;
        $this->rosterWarningsAccepted = false;
    }

    public function acceptRosterWarnings(): void
    {
        $this->rosterValidationRan = true;
        $this->rosterWarningsAccepted = true;
    }

    public function applyRosterTemplate(): void
    {
        if (! $this->ensureSchemaReady()) {
            return;
        }

        $template = $this->rosterTemplates()->firstWhere('key', $this->rosterTemplateKey);
        if (! is_array($template)) {
            $this->addError('rosterTemplateKey', __('Choose a roster template to apply.'));

            return;
        }

        $this->rosterShiftTemplateId = (string) ($template['shift_id'] ?? '');
        $this->rosterPatternId = (string) ($template['pattern_id'] ?? '');
        $this->rosterPublishState = 'draft';
    }

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
                'publish_state' => 'draft',
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

    public function saveCellOverride(int $employeeId, string $date, mixed $shiftTemplateId = null, mixed $policyGroupId = null): void
    {
        if (! $this->ensureSchemaReady()) {
            return;
        }

        $this->authorizeAttendance('people.attendance.manage');

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
        if ($assignment instanceof AttendanceRosterAssignment) {
            $this->appendExceptionOverride($assignment, $date, (int) $shiftTemplate->id, (int) $policyGroup->id, 'cell_override');
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
            'publish_state' => 'draft',
            'lock_state' => 'open',
            'revision' => 1,
            'exceptions' => [],
            'metadata' => ['created_from' => 'attendance_roster_cell_override'],
        ]);

        $this->lastDraftAssignmentIds = [$created->id];
        session()->flash('success', __('Roster cell override saved.'));
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

        $from = $this->assignmentForEmployeeDate((int) $this->swapFromEmployeeId, $this->swapDate);
        $to = $this->assignmentForEmployeeDate((int) $this->swapToEmployeeId, $this->swapDate);

        if (! $from instanceof AttendanceRosterAssignment || ! $to instanceof AttendanceRosterAssignment) {
            $this->addError('swapDate', __('Both employees need an assignment on the selected date before swapping.'));

            return;
        }

        $this->appendExceptionOverride($from, $this->swapDate, (int) $to->attendance_shift_template_id, (int) $to->attendance_policy_group_id, 'swap');
        $this->appendExceptionOverride($to, $this->swapDate, (int) $from->attendance_shift_template_id, (int) $from->attendance_policy_group_id, 'swap');
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
                'publish_state' => 'draft',
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

    public function publishReviewedRosters(): void
    {
        if (! $this->ensureSchemaReady()) {
            return;
        }

        $this->authorizeAttendance('people.attendance.manage');
        $findings = $this->rosterValidationFindings();
        $blocking = collect($findings)->where('severity', 'error')->isNotEmpty();
        $warnings = collect($findings)->where('severity', 'warning')->isNotEmpty();

        if (! $this->rosterValidationRan || $blocking || ($warnings && ! $this->rosterWarningsAccepted)) {
            $this->addError('rosterRevisionNote', __('Run validation and accept warnings before publishing.'));

            return;
        }

        $idsQuery = AttendanceRosterAssignment::query()
            ->where('company_id', $this->companyId())
            ->where('publish_state', 'draft')
            ->whereDate('effective_from', '<=', $this->safeGridEndDate($this->safeGridStartDate())->toDateString())
            ->where(function ($query): void {
                $query->whereNull('effective_to')
                    ->orWhereDate('effective_to', '>=', $this->safeGridStartDate()->toDateString());
            });

        $employeeIds = $this->selectedRosterEmployeeIds();
        if ($employeeIds !== []) {
            $idsQuery->whereIn('employee_id', $employeeIds);
        } elseif ($this->lastDraftAssignmentIds !== []) {
            $idsQuery->whereIn('id', $this->lastDraftAssignmentIds);
        } else {
            $this->addError('rosterRevisionNote', __('Select roster rows or use the latest draft operation before publishing.'));

            return;
        }

        $ids = $idsQuery->pluck('id');

        $published = 0;
        foreach ($ids as $id) {
            $assignment = AttendanceRosterAssignment::query()->find($id);
            if (! $assignment instanceof AttendanceRosterAssignment) {
                continue;
            }

            $assignment->forceFill([
                'publish_state' => 'published',
                'revision' => ((int) $assignment->revision) + 1,
                'metadata' => [
                    ...($assignment->metadata ?? []),
                    'published_from' => 'attendance_roster_builder',
                    'revision_note' => $this->rosterRevisionNote,
                    'warnings_accepted' => $this->rosterWarningsAccepted,
                    'published_at' => now()->toIso8601String(),
                ],
            ])->save();

            PeopleNotificationDeliveryLog::query()->create([
                'company_id' => $this->companyId(),
                'notifiable_type' => AttendanceRosterAssignment::class,
                'notifiable_id' => $assignment->id,
                'channel' => 'intent',
                'recipient' => (string) $assignment->employee_id,
                'subject' => 'attendance.roster.published',
                'status' => 'queued',
                'metadata' => [
                    'event' => 'roster_published',
                    'employee_id' => $assignment->employee_id,
                    'effective_from' => $assignment->effective_from?->toDateString(),
                    'effective_to' => $assignment->effective_to?->toDateString(),
                ],
            ]);

            $published++;
        }

        $this->rosterRevisionNote = '';
        $this->rosterValidationRan = false;
        $this->rosterWarningsAccepted = false;
        session()->flash('success', trans_choice('Published :count roster assignment.|Published :count roster assignments.', $published, ['count' => $published]));
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
            ->where('publish_state', 'draft')
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

        if ($this->editingRosterAssignmentId !== '') {
            $this->updateExistingRosterAssignment();

            return;
        }

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
            'rosterPublishState' => ['required', Rule::in(['draft', 'published'])],
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
                'publish_state' => $validated['rosterPublishState'],
                'lock_state' => 'open',
                'revision' => 1,
                'exceptions' => [],
                'metadata' => [
                    'created_from' => 'attendance_roster_builder',
                    'selection_mode' => $this->rosterSelectAllFiltered ? 'all_filtered' : 'selected_employees',
                    'filters' => $this->rosterFilters(),
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
        $this->resetForm();
        $this->mode = 'list';
        session()->flash('success', trans_choice(
            'Roster assignment saved. :skipped skipped because of existing roster overlaps.|:count roster assignments saved. :skipped skipped because of existing roster overlaps.',
            $created,
            ['count' => $created, 'skipped' => $skipped],
        ));
    }

    private function updateExistingRosterAssignment(): void
    {
        $companyId = $this->companyId();

        $assignment = AttendanceRosterAssignment::query()
            ->where('company_id', $companyId)
            ->findOrFail((int) $this->editingRosterAssignmentId);

        $validated = $this->validate([
            'rosterPatternId' => ['nullable', 'integer', Rule::exists(AttendanceRosterPattern::class, 'id')->where('company_id', $companyId)],
            'rosterShiftTemplateId' => ['required', 'integer', Rule::exists(AttendanceShiftTemplate::class, 'id')->where('company_id', $companyId)],
            'rosterPolicyGroupId' => ['required', 'integer', Rule::exists(AttendancePolicyGroup::class, 'id')->where('company_id', $companyId)],
            'rosterEffectiveFrom' => ['required', 'date'],
            'rosterEffectiveTo' => ['nullable', 'date', 'after_or_equal:rosterEffectiveFrom'],
            'rosterPublishState' => ['required', Rule::in(['draft', 'published'])],
        ]);

        $effectiveTo = $this->blankToNull($validated['rosterEffectiveTo'] ?? null);

        if ($assignment->employee_id !== null && $this->hasRosterOverlap(
            (int) $assignment->employee_id,
            $validated['rosterEffectiveFrom'],
            $effectiveTo,
            (int) $assignment->id,
        )) {
            $this->addError('rosterEffectiveFrom', __('This range overlaps another roster assignment for the same employee.'));

            return;
        }

        $assignment->update([
            'attendance_roster_pattern_id' => $this->blankToNull($validated['rosterPatternId'] ?? null),
            'attendance_shift_template_id' => (int) $validated['rosterShiftTemplateId'],
            'attendance_policy_group_id' => (int) $validated['rosterPolicyGroupId'],
            'effective_from' => $validated['rosterEffectiveFrom'],
            'effective_to' => $effectiveTo,
            'publish_state' => $validated['rosterPublishState'],
            'revision' => ((int) $assignment->revision) + 1,
        ]);

        $this->resetForm();
        $this->mode = 'list';
        session()->flash('success', __('Roster assignment updated.'));
    }

    public function startNewRosterAssignment(): void
    {
        if (! $this->ensureSchemaReady()) {
            return;
        }

        $this->authorizeAttendance('people.attendance.manage');

        $this->resetForm();
        $this->mode = 'form';
    }

    public function cancelRosterForm(): void
    {
        $this->resetForm();
        $this->mode = 'list';
    }

    public function goToPreviousWeek(): void
    {
        if ($this->listScope === 'month') {
            $this->listWeekAnchor = $this->listScopeStart()->subMonth()->toDateString();

            return;
        }

        $this->listWeekAnchor = $this->listWeekStart()->subDays(7)->toDateString();
    }

    public function goToNextWeek(): void
    {
        if ($this->listScope === 'month') {
            $this->listWeekAnchor = $this->listScopeStart()->addMonth()->toDateString();

            return;
        }

        $this->listWeekAnchor = $this->listWeekStart()->addDays(7)->toDateString();
    }

    public function goToThisWeek(): void
    {
        $this->listWeekAnchor = '';
    }

    public function setListScope(string $scope): void
    {
        $this->listScope = in_array($scope, ['week', 'month'], true) ? $scope : 'week';
    }

    private function listWeekStart(): CarbonImmutable
    {
        if ($this->listWeekAnchor !== '') {
            try {
                return CarbonImmutable::parse($this->listWeekAnchor)->startOfWeek(CarbonImmutable::MONDAY);
            } catch (\Throwable) {
                // fall through to today's week
            }
        }

        return CarbonImmutable::today()->startOfWeek(CarbonImmutable::MONDAY);
    }

    /**
     * Start of the currently-browsed period, regardless of zoom level. Month
     * scope returns the first day of the month containing the anchor; week
     * scope returns the Monday of the anchor's week.
     */
    private function listScopeStart(): CarbonImmutable
    {
        $anchor = $this->listWeekAnchor !== ''
            ? (function (): CarbonImmutable {
                try {
                    return CarbonImmutable::parse($this->listWeekAnchor);
                } catch (\Throwable) {
                    return CarbonImmutable::today();
                }
            })()
            : CarbonImmutable::today();

        if ($this->listScope === 'month') {
            return $anchor->startOfMonth();
        }

        return $anchor->startOfWeek(CarbonImmutable::MONDAY);
    }

    private function listScopeEnd(): CarbonImmutable
    {
        $start = $this->listScopeStart();

        return $this->listScope === 'month'
            ? $start->endOfMonth()
            : $start->addDays(6);
    }

    private function gridPeriodStart(): CarbonImmutable
    {
        return $this->mode === 'list'
            ? $this->listScopeStart()
            : $this->safeGridStartDate();
    }

    private function gridPeriodEnd(): CarbonImmutable
    {
        return $this->mode === 'list'
            ? $this->listScopeEnd()
            : $this->safeGridEndDate($this->safeGridStartDate());
    }

    /**
     * @param  Collection<int, PeopleReferenceEntry>  $workforceClasses
     * @param  Collection<int, Department>  $departments
     * @return array{
     *     hasActiveFilters: bool,
     *     departmentLabel: string,
     *     workforceClassLabel: string,
     *     statusLabel: string,
     * }
     */
    public function rosterListFilterContext(Collection $departments, Collection $workforceClasses): array
    {
        $departmentLabel = __('all departments');
        if ($this->rosterDepartmentId !== '') {
            $match = $departments->firstWhere('id', (int) $this->rosterDepartmentId);
            if ($match !== null) {
                $departmentLabel = (string) $match->name;
            }
        }

        $workforceClassLabel = __('all workforce classes');
        if ($this->rosterWorkforceClassId !== '') {
            $match = $workforceClasses->firstWhere('id', (int) $this->rosterWorkforceClassId);
            if ($match !== null) {
                $workforceClassLabel = (string) $match->name;
            }
        }

        $statusLabel = match ($this->rosterEmployeeStatus) {
            'active' => __('active'),
            'probation' => __('probation'),
            'pending' => __('pending'),
            'inactive' => __('inactive'),
            'terminated' => __('terminated'),
            default => __('any status'),
        };

        $hasActiveFilters = $this->rosterDepartmentId !== ''
            || $this->rosterWorkforceClassId !== ''
            || $this->rosterEmployeeStatus !== 'active'
            || $this->rosterSearch !== ''
            || $this->rosterSupervisorId !== ''
            || $this->rosterOrganizationUnitId !== ''
            || $this->rosterCostCenterId !== ''
            || $this->rosterEmploymentGroupId !== ''
            || $this->rosterWorkCalendarId !== ''
            || $this->rosterPayRateType !== '';

        return [
            'hasActiveFilters' => $hasActiveFilters,
            'departmentLabel' => $departmentLabel,
            'workforceClassLabel' => $workforceClassLabel,
            'statusLabel' => $statusLabel,
        ];
    }

    /**
     * Build a short narrative for the calendar in list mode that names how many
     * gaps and exceptions live in the visible window. Counts come from the
     * already-computed grid rows, so this adds no DB queries.
     *
     * @param  Collection<int, array<string, mixed>>  $rows
     * @param  list<array{date: string, day: string, label: string}>  $days
     * @return array{gaps: int, exceptions: int, sentence: string, hasIssues: bool}
     */
    private function rosterListSummary(Collection $rows, array $days): array
    {
        ['gaps' => $gaps, 'exceptions' => $exceptions] = $this->countRosterIssues($rows, $days);
        $hasIssues = $gaps > 0 || $exceptions > 0;
        $periodEnd = $days === [] ? null : end($days)['label'] ?? null;

        return [
            'gaps' => $gaps,
            'exceptions' => $exceptions,
            'sentence' => $this->rosterSummarySentence($gaps, $exceptions, $periodEnd),
            'hasIssues' => $hasIssues,
        ];
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @param  list<array{date: string, day: string, label: string}>  $days
     * @return array{gaps: int, exceptions: int}
     */
    private function countRosterIssues(Collection $rows, array $days): array
    {
        $gaps = 0;
        $exceptions = 0;

        foreach ($rows as $row) {
            foreach ($days as $day) {
                $cell = $row['cells'][$day['date']] ?? null;
                if (! is_array($cell)) {
                    continue;
                }

                $isWorking = ($cell['day_type'] ?? AttendanceDay::DAY_TYPE_NORMAL) === AttendanceDay::DAY_TYPE_NORMAL;
                $isEmpty = ($cell['state'] ?? 'empty') === 'empty';

                if ($isWorking && $isEmpty) {
                    $gaps++;
                } elseif (! $isWorking && ! $isEmpty) {
                    $exceptions++;
                }
            }
        }

        return ['gaps' => $gaps, 'exceptions' => $exceptions];
    }

    private function rosterSummarySentence(int $gaps, int $exceptions, ?string $periodEnd): string
    {
        if ($gaps === 0 && $exceptions === 0) {
            return $periodEnd
                ? __('All set through :date.', ['date' => $periodEnd])
                : __('All set.');
        }

        $parts = [];
        if ($gaps > 0) {
            $parts[] = trans_choice(':count gap|:count gaps', $gaps, ['count' => $gaps]);
        }
        if ($exceptions > 0) {
            $parts[] = trans_choice(':count exception|:count exceptions', $exceptions, ['count' => $exceptions]);
        }
        $listLabel = ucfirst(implode(' '.__('and').' ', $parts));

        return $periodEnd
            ? __(':items to sort out before :date.', ['items' => $listLabel, 'date' => $periodEnd])
            : __(':items to sort out.', ['items' => $listLabel]);
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

    public function editRosterAssignment(int $assignmentId): void
    {
        if (! $this->ensureSchemaReady()) {
            return;
        }

        $this->authorizeAttendance('people.attendance.manage');

        $assignment = AttendanceRosterAssignment::query()
            ->where('company_id', $this->companyId())
            ->findOrFail($assignmentId);

        $this->resetForm();

        $this->editingRosterAssignmentId = (string) $assignment->id;
        $this->rosterEmployeeId = (string) ($assignment->employee_id ?? '');
        $this->selectedRosterEmployeeIds = $assignment->employee_id !== null
            ? [(string) $assignment->employee_id]
            : [];
        $this->rosterPatternId = (string) ($assignment->attendance_roster_pattern_id ?? '');
        $this->rosterShiftTemplateId = (string) ($assignment->attendance_shift_template_id ?? '');
        $this->rosterPolicyGroupId = (string) ($assignment->attendance_policy_group_id ?? '');
        $this->rosterEffectiveFrom = $assignment->effective_from?->toDateString() ?? now()->toDateString();
        $this->rosterEffectiveTo = $assignment->effective_to?->toDateString() ?? '';
        $this->rosterPublishState = (string) ($assignment->publish_state ?? 'draft');

        $this->mode = 'form';
    }

    public function render(): View
    {
        $companyId = $this->companyId();
        $schemaReady = $this->schemaReady();

        $viewData = $schemaReady
            ? $this->renderDataForReadySchema($companyId)
            : $this->renderDataForUnreadySchema();

        return view('livewire.people.attendance.rosters', [
            'schemaReady' => $schemaReady,
            'canManage' => $this->canAttendance('people.attendance.manage'),
            'organizationUnits' => $this->referenceOptions(PeopleReferenceEntry::TYPE_ORGANIZATION_UNIT, $schemaReady),
            'costCenters' => $this->referenceOptions(PeopleReferenceEntry::TYPE_COST_CENTER, $schemaReady),
            'employmentGroups' => $this->referenceOptions(PeopleReferenceEntry::TYPE_EMPLOYMENT_GROUP, $schemaReady),
            'workforceClasses' => $this->referenceOptions(PeopleReferenceEntry::TYPE_WORKFORCE_CLASS, $schemaReady),
            'workCalendars' => $this->referenceOptions(PeopleReferenceEntry::TYPE_WORK_CALENDAR, $schemaReady),
            ...$viewData,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function renderDataForReadySchema(int $companyId): array
    {
        $employees = $this->filteredEmployeesQuery()
            ->orderBy('full_name')
            ->orderBy('id')
            ->paginate(25);

        $rosterGridDays = $this->rosterGridDays();
        $rosterGridRows = $this->rosterGridRows($employees->getCollection());
        $rosterGridDays = $this->enrichGridDays($rosterGridDays, $rosterGridRows);
        $rosterCoverageRows = $this->rosterCoverageRows();

        return [
            'employees' => $employees,
            'editingEmployee' => $this->resolveEditingEmployee($companyId),
            'filteredEmployeeCount' => $this->filteredEmployeesQuery()->count(),
            'companyEmployeeCount' => Employee::query()->where('company_id', $companyId)->count(),
            'selectedEmployeeCount' => count($this->selectedRosterEmployeeIds()),
            'rosterGridDays' => $rosterGridDays,
            'rosterGridRows' => $rosterGridRows,
            'rosterListSummary' => $this->rosterListSummary($rosterGridRows, $rosterGridDays),
            'rosterCoverageRows' => $rosterCoverageRows,
            'rosterCoverageMatrix' => $this->rosterCoverageMatrix($rosterCoverageRows),
            'rosterValidationFindings' => $this->rosterValidationFindings(),
            'rosterTemplates' => $this->rosterTemplates(),
            'spreadsheetPreviewRows' => $this->parseSpreadsheetRows()['rows'],
            'departments' => Department::query()
                ->where('company_id', $companyId)
                ->with('type')
                ->orderBy('name')
                ->get(),
            'supervisors' => Employee::query()
                ->where('company_id', $companyId)
                ->whereNotNull('id')
                ->orderBy('full_name')
                ->get(['id', 'full_name', 'employee_number']),
            'shiftTemplates' => AttendanceShiftTemplate::query()
                ->where('company_id', $companyId)
                ->orderBy('code')
                ->get(),
            'policyGroups' => AttendancePolicyGroup::query()
                ->where('company_id', $companyId)
                ->orderBy('code')
                ->get(),
            'rosterPatterns' => AttendanceRosterPattern::query()
                ->where('company_id', $companyId)
                ->orderBy('code')
                ->get(),
            'rosterAssignments' => AttendanceRosterAssignment::query()
                ->where('company_id', $companyId)
                ->with(['employee', 'shiftTemplate', 'policyGroup', 'rosterPattern'])
                ->latest('effective_from')
                ->limit(40)
                ->get(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function renderDataForUnreadySchema(): array
    {
        return [
            'employees' => collect(),
            'editingEmployee' => null,
            'filteredEmployeeCount' => 0,
            'companyEmployeeCount' => 0,
            'selectedEmployeeCount' => 0,
            'rosterGridDays' => [],
            'rosterGridRows' => collect(),
            'rosterListSummary' => null,
            'rosterCoverageRows' => [],
            'rosterCoverageMatrix' => ['shifts' => [], 'dates' => [], 'cells' => []],
            'rosterValidationFindings' => [],
            'rosterTemplates' => collect(),
            'spreadsheetPreviewRows' => [],
            'departments' => collect(),
            'supervisors' => collect(),
            'shiftTemplates' => collect(),
            'policyGroups' => collect(),
            'rosterPatterns' => collect(),
            'rosterAssignments' => collect(),
        ];
    }

    private function resolveEditingEmployee(int $companyId): ?Employee
    {
        if ($this->editingRosterAssignmentId === '' || $this->rosterEmployeeId === '') {
            return null;
        }

        return Employee::query()
            ->where('company_id', $companyId)
            ->whereKey((int) $this->rosterEmployeeId)
            ->first();
    }

    private function hasRosterOverlap(int $employeeId, string $effectiveFrom, ?string $effectiveTo, ?int $excludeAssignmentId = null): bool
    {
        $rangeEnd = $effectiveTo ?? '9999-12-31';

        return AttendanceRosterAssignment::query()
            ->where('company_id', $this->companyId())
            ->where('employee_id', $employeeId)
            ->where('effective_from', '<=', $rangeEnd)
            ->where(function ($query) use ($effectiveFrom): void {
                $query->whereNull('effective_to')
                    ->orWhere('effective_to', '>=', $effectiveFrom);
            })
            ->when($excludeAssignmentId !== null, fn ($query) => $query->where('id', '!=', $excludeAssignmentId))
            ->exists();
    }

    private function resetForm(): void
    {
        $this->editingRosterAssignmentId = '';
        $this->rosterEmployeeId = '';
        $this->selectedRosterEmployeeIds = [];
        $this->rosterSelectAllFiltered = false;
        $this->rosterPatternId = '';
        $this->rosterShiftTemplateId = '';
        $this->rosterPolicyGroupId = '';
        $this->rosterEffectiveFrom = now()->toDateString();
        $this->rosterEffectiveTo = '';
        $this->rosterPublishState = 'draft';
    }

    /**
     * @return Builder<Employee>
     */
    private function filteredEmployeesQuery(): Builder
    {
        $query = Employee::query()
            ->select('employees.*')
            ->leftJoin('people_employee_work_profiles', 'people_employee_work_profiles.employee_id', '=', 'employees.id')
            ->where('employees.company_id', $this->companyId());

        $search = trim($this->rosterSearch);
        if ($search !== '') {
            $query->where(function (Builder $searchQuery) use ($search): void {
                $like = '%'.$search.'%';

                $searchQuery->where('employees.full_name', 'like', $like)
                    ->orWhere('employees.short_name', 'like', $like)
                    ->orWhere('employees.employee_number', 'like', $like)
                    ->orWhere('employees.designation', 'like', $like);
            });
        }

        $this->applyIntegerFilter($query, 'employees.department_id', $this->rosterDepartmentId);
        $this->applyIntegerFilter($query, 'employees.supervisor_id', $this->rosterSupervisorId);
        $this->applyIntegerFilter($query, 'people_employee_work_profiles.organization_unit_id', $this->rosterOrganizationUnitId);
        $this->applyIntegerFilter($query, 'people_employee_work_profiles.cost_center_id', $this->rosterCostCenterId);
        $this->applyIntegerFilter($query, 'people_employee_work_profiles.workforce_class_id', $this->rosterWorkforceClassId);
        $this->applyIntegerFilter($query, 'people_employee_work_profiles.employment_group_id', $this->rosterEmploymentGroupId);
        $this->applyIntegerFilter($query, 'people_employee_work_profiles.work_calendar_id', $this->rosterWorkCalendarId);

        if ($this->rosterPayRateType !== '') {
            $query->where('people_employee_work_profiles.pay_rate_type', $this->rosterPayRateType);
        }

        if ($this->rosterEmployeeStatus !== '') {
            $query->where('employees.status', $this->rosterEmployeeStatus);
        }

        return $query->with(['department.type', 'workProfile.organizationUnit', 'workProfile.costCenter', 'workProfile.workforceClass']);
    }

    /**
     * @return list<int>
     */
    private function selectedRosterEmployeeIds(): array
    {
        if ($this->rosterSelectAllFiltered) {
            return $this->filteredEmployeesQuery()
                ->orderBy('employees.id')
                ->limit(500)
                ->pluck('employees.id')
                ->map(fn (int $id): int => $id)
                ->all();
        }

        $ids = array_filter($this->selectedRosterEmployeeIds, fn (mixed $id): bool => filter_var($id, FILTER_VALIDATE_INT) !== false);

        if ($ids === [] && filter_var($this->rosterEmployeeId, FILTER_VALIDATE_INT) !== false) {
            $ids = [$this->rosterEmployeeId];
        }

        $companyId = $this->companyId();

        return Employee::query()
            ->where('company_id', $companyId)
            ->whereIn('id', array_map('intval', $ids))
            ->pluck('id')
            ->map(fn (int $id): int => $id)
            ->all();
    }

    /**
     * @return array<string, string>
     */
    private function rosterFilters(): array
    {
        return [
            'search' => $this->rosterSearch,
            'department_id' => $this->rosterDepartmentId,
            'supervisor_id' => $this->rosterSupervisorId,
            'organization_unit_id' => $this->rosterOrganizationUnitId,
            'cost_center_id' => $this->rosterCostCenterId,
            'workforce_class_id' => $this->rosterWorkforceClassId,
            'employment_group_id' => $this->rosterEmploymentGroupId,
            'work_calendar_id' => $this->rosterWorkCalendarId,
            'pay_rate_type' => $this->rosterPayRateType,
            'status' => $this->rosterEmployeeStatus,
        ];
    }

    /**
     * @return list<string>
     */
    private function rosterFilterProperties(): array
    {
        return [
            'rosterSearch',
            'rosterDepartmentId',
            'rosterSupervisorId',
            'rosterOrganizationUnitId',
            'rosterCostCenterId',
            'rosterWorkforceClassId',
            'rosterEmploymentGroupId',
            'rosterWorkCalendarId',
            'rosterPayRateType',
            'rosterEmployeeStatus',
        ];
    }

    private function applyIntegerFilter(Builder $query, string $column, mixed $value): void
    {
        if (filter_var($value, FILTER_VALIDATE_INT) === false) {
            return;
        }

        $query->where($column, (int) $value);
    }

    private function referenceOptions(string $type, bool $schemaReady)
    {
        if (! $schemaReady) {
            return collect();
        }

        return PeopleReferenceEntry::query()
            ->where('company_id', $this->companyId())
            ->where('type', $type)
            ->orderBy('name')
            ->get();
    }

    /**
     * @return list<array{date: string, day: string, label: string}>
     */
    private function rosterGridDays(): array
    {
        $start = $this->gridPeriodStart();
        $end = $this->gridPeriodEnd();
        $days = [];

        for ($date = $start; $date->lessThanOrEqualTo($end); $date = $date->addDay()) {
            $days[] = [
                'date' => $date->toDateString(),
                'day' => $date->format('D'),
                'label' => $date->format('M j'),
            ];
        }

        return $days;
    }

    /**
     * @param  Collection<int, Employee>  $employees
     * @return Collection<int, array<string, mixed>>
     */
    private function rosterGridRows(Collection $employees): Collection
    {
        if ($employees->isEmpty()) {
            return collect();
        }

        $days = $this->rosterGridDays();
        $start = $days[0]['date'] ?? now()->toDateString();
        $end = $days[array_key_last($days)]['date'] ?? $start;
        $employeeIds = $employees->pluck('id')->map(fn (int $id): int => $id)->all();
        $selectedIds = $this->selectedRosterEmployeeIds();
        $selectedLookup = array_fill_keys($selectedIds, true);
        $proposedShift = $this->selectedShiftTemplateCode();

        $assignments = AttendanceRosterAssignment::query()
            ->where('company_id', $this->companyId())
            ->whereIn('employee_id', $employeeIds)
            ->whereDate('effective_from', '<=', $end)
            ->where(function ($query) use ($start): void {
                $query->whereNull('effective_to')
                    ->orWhereDate('effective_to', '>=', $start);
            })
            ->with(['shiftTemplate', 'policyGroup', 'rosterPattern'])
            ->orderBy('effective_from')
            ->get()
            ->groupBy('employee_id');

        $calendar = app(AttendanceCalendarResolver::class);
        $calendar->preload($employees, $start, $end);

        return $employees->map(function (Employee $employee) use ($assignments, $days, $selectedLookup, $proposedShift, $calendar): array {
            $employeeAssignments = $assignments->get($employee->id, collect());

            return [
                'employee' => $employee,
                'group' => $this->employeeGroupLabel($employee),
                'cells' => collect($days)->mapWithKeys(
                    fn (array $day): array => [
                        $day['date'] => $this->rosterGridCell($employee, $day, $employeeAssignments, $selectedLookup, $proposedShift, $calendar),
                    ],
                )->all(),
            ];
        })->sortBy('group')->values();
    }

    private function employeeGroupLabel(Employee $employee): string
    {
        return $employee->department?->name
            ?? $employee->workProfile?->organizationUnit?->name
            ?? $employee->workProfile?->workforceClass?->name
            ?? '-';
    }

    /**
     * @param  array{date: string, day: string, label: string}  $day
     * @param  Collection<int, AttendanceRosterAssignment>  $employeeAssignments
     * @param  array<int, true>  $selectedLookup
     * @return array<string, mixed>
     */
    private function rosterGridCell(
        Employee $employee,
        array $day,
        Collection $employeeAssignments,
        array $selectedLookup,
        ?string $proposedShift,
        AttendanceCalendarResolver $calendar,
    ): array {
        $dayType = $calendar->dayType($employee, $day['date']);
        $dayTypeLabel = $this->dayTypeLabel($dayType);
        $assignment = $this->assignmentForGridDate($employeeAssignments, $day['date']);

        if ($assignment instanceof AttendanceRosterAssignment) {
            return $this->buildAssignedCell($assignment, $day, $dayType, $dayTypeLabel);
        }

        if (isset($selectedLookup[$employee->id]) && $proposedShift !== null && $this->dateWithinDraftRange($day['date'])) {
            return $this->buildPreviewCell($proposedShift, $dayType, $dayTypeLabel);
        }

        return $this->buildEmptyCell($dayType, $dayTypeLabel);
    }

    /**
     * @param  array{date: string, day: string, label: string}  $day
     * @return array<string, mixed>
     */
    private function buildAssignedCell(
        AttendanceRosterAssignment $assignment,
        array $day,
        string $dayType,
        string $dayTypeLabel,
    ): array {
        $title = __(':state assignment, policy :policy', [
            'state' => $this->statusLabel($assignment->publish_state),
            'policy' => $assignment->policyGroup?->code ?? '-',
        ]);
        if ($dayType !== AttendanceDay::DAY_TYPE_NORMAL) {
            $title .= ' · '.__('on :day', ['day' => $dayTypeLabel]);
        }

        return [
            'label' => $this->shiftCodeForGrid($assignment, $day['date']),
            'state' => $assignment->publish_state,
            'variant' => $assignment->publish_state === 'published' ? 'success' : 'warning',
            'policy' => $assignment->policyGroup?->code ?? '-',
            'title' => $title,
            'day_type' => $dayType,
            'day_type_label' => $dayTypeLabel,
            'on_non_working_day' => $dayType !== AttendanceDay::DAY_TYPE_NORMAL,
            'shift_template_id' => (int) ($assignment->attendance_shift_template_id ?? 0),
            'policy_group_id' => (int) ($assignment->attendance_policy_group_id ?? 0),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildPreviewCell(string $proposedShift, string $dayType, string $dayTypeLabel): array
    {
        $title = __('Unsaved roster preview');
        if ($dayType !== AttendanceDay::DAY_TYPE_NORMAL) {
            $title .= ' · '.__('on :day', ['day' => $dayTypeLabel]);
        }

        return [
            'label' => $proposedShift,
            'state' => 'preview',
            'variant' => 'info',
            'policy' => $this->selectedPolicyGroupCode() ?? '-',
            'title' => $title,
            'day_type' => $dayType,
            'day_type_label' => $dayTypeLabel,
            'on_non_working_day' => $dayType !== AttendanceDay::DAY_TYPE_NORMAL,
            'shift_template_id' => (int) ($this->rosterShiftTemplateId ?: 0),
            'policy_group_id' => (int) ($this->rosterPolicyGroupId ?: 0),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildEmptyCell(string $dayType, string $dayTypeLabel): array
    {
        $isNormal = $dayType === AttendanceDay::DAY_TYPE_NORMAL;

        return [
            'label' => $isNormal ? '-' : $dayTypeLabel,
            'state' => 'empty',
            'variant' => 'default',
            'policy' => '-',
            'title' => $isNormal
                ? __('No roster assignment')
                : __(':day — no assignment', ['day' => $dayTypeLabel]),
            'day_type' => $dayType,
            'day_type_label' => $dayTypeLabel,
            'on_non_working_day' => false,
            'shift_template_id' => 0,
            'policy_group_id' => 0,
        ];
    }

    private function dayTypeLabel(string $dayType): string
    {
        return DayTypeVocabulary::label($dayType);
    }

    /**
     * Add per-date markers — is_today, is_weekend, and whether this date is a
     * public holiday for at least one rendered employee — so the roster grid
     * can highlight columns without recomputing day types from row data.
     *
     * @param  list<array{date: string, day: string, label: string}>  $days
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return list<array{date: string, day: string, day_short: string, label: string, is_today: bool, is_weekend: bool, is_holiday: bool}>
     */
    private function enrichGridDays(array $days, Collection $rows): array
    {
        $today = CarbonImmutable::today()->toDateString();

        $holidayDates = [];
        foreach ($rows as $row) {
            foreach (($row['cells'] ?? []) as $date => $cell) {
                if (($cell['day_type'] ?? null) === AttendanceDay::DAY_TYPE_HOLIDAY) {
                    $holidayDates[$date] = true;
                }
            }
        }

        return array_map(function (array $day) use ($today, $holidayDates): array {
            $carbon = CarbonImmutable::parse($day['date']);
            $weekday = (int) $carbon->dayOfWeek; // 0 = Sunday, 6 = Saturday

            return [
                ...$day,
                'day_short' => substr($day['day'], 0, 1),
                'is_today' => $day['date'] === $today,
                'is_weekend' => $weekday === 0 || $weekday === 6,
                'is_holiday' => isset($holidayDates[$day['date']]),
            ];
        }, $days);
    }

    private function safeGridStartDate(): CarbonImmutable
    {
        try {
            return CarbonImmutable::parse($this->rosterEffectiveFrom);
        } catch (\Throwable) {
            return CarbonImmutable::parse(now()->toDateString());
        }
    }

    private function safeGridEndDate(CarbonImmutable $start): CarbonImmutable
    {
        try {
            $end = $this->rosterEffectiveTo === ''
                ? $start->addDays(6)
                : CarbonImmutable::parse($this->rosterEffectiveTo);
        } catch (\Throwable) {
            $end = $start->addDays(6);
        }

        if ($end->lessThan($start)) {
            return $start;
        }

        return $end->greaterThan($start->addDays(30)) ? $start->addDays(30) : $end;
    }

    /**
     * @param  Collection<int, AttendanceRosterAssignment>  $assignments
     */
    private function assignmentForGridDate(Collection $assignments, string $date): ?AttendanceRosterAssignment
    {
        return $assignments
            ->filter(fn (AttendanceRosterAssignment $assignment): bool => $assignment->effective_from?->toDateString() <= $date
                && ($assignment->effective_to === null || $assignment->effective_to->toDateString() >= $date))
            ->sortByDesc(fn (AttendanceRosterAssignment $assignment): string => $assignment->effective_from?->toDateString() ?? '')
            ->first();
    }

    private function shiftCodeForGrid(AttendanceRosterAssignment $assignment, string $date): string
    {
        $exception = $this->exceptionForGridDate($assignment, $date);
        if (is_array($exception) && filter_var($exception['attendance_shift_template_id'] ?? null, FILTER_VALIDATE_INT) !== false) {
            return AttendanceShiftTemplate::query()
                ->where('company_id', $this->companyId())
                ->whereKey((int) $exception['attendance_shift_template_id'])
                ->value('code') ?? '-';
        }

        $patternShift = $this->patternShiftCodeForGrid($assignment, $date);

        return $patternShift ?? $assignment->shiftTemplate?->code ?? '-';
    }

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
