<?php

namespace App\Modules\People\Attendance\Livewire;

use App\Modules\Core\Employee\Models\Employee;
use App\Modules\People\Attendance\Livewire\Concerns\InteractsWithAttendanceScreen;
use App\Modules\People\Attendance\Models\AttendancePolicyGroup;
use App\Modules\People\Attendance\Models\AttendanceRosterAssignment;
use App\Modules\People\Attendance\Models\AttendanceRosterPattern;
use App\Modules\People\Attendance\Models\AttendanceShiftTemplate;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\Rule;
use Livewire\Component;

class Rosters extends Component
{
    use InteractsWithAttendanceScreen;

    public string $rosterEmployeeId = '';

    public string $rosterPatternId = '';

    public string $rosterShiftTemplateId = '';

    public string $rosterPolicyGroupId = '';

    public string $rosterEffectiveFrom = '';

    public string $rosterEffectiveTo = '';

    public string $rosterPublishState = 'draft';

    public function mount(): void
    {
        $this->rosterEffectiveFrom = now()->toDateString();
    }

    public function saveRosterAssignment(): void
    {
        if (! $this->ensureSchemaReady()) {
            return;
        }

        $this->authorizeAttendance('people.attendance.manage');

        $companyId = $this->companyId();
        $validated = $this->validate([
            'rosterEmployeeId' => ['required', 'integer', Rule::exists(Employee::class, 'id')->where('company_id', $companyId)],
            'rosterPatternId' => ['nullable', 'integer', Rule::exists(AttendanceRosterPattern::class, 'id')->where('company_id', $companyId)],
            'rosterShiftTemplateId' => ['required', 'integer', Rule::exists(AttendanceShiftTemplate::class, 'id')->where('company_id', $companyId)],
            'rosterPolicyGroupId' => ['required', 'integer', Rule::exists(AttendancePolicyGroup::class, 'id')->where('company_id', $companyId)],
            'rosterEffectiveFrom' => ['required', 'date'],
            'rosterEffectiveTo' => ['nullable', 'date', 'after_or_equal:rosterEffectiveFrom'],
            'rosterPublishState' => ['required', Rule::in(['draft', 'published'])],
        ]);

        if ($this->hasRosterOverlap((int) $validated['rosterEmployeeId'], $validated['rosterEffectiveFrom'], $this->blankToNull($validated['rosterEffectiveTo'] ?? null))) {
            $this->addError('rosterEffectiveFrom', __('This employee already has a roster assignment in that date range.'));

            return;
        }

        AttendanceRosterAssignment::query()->create([
            'company_id' => $companyId,
            'employee_id' => (int) $validated['rosterEmployeeId'],
            'attendance_roster_pattern_id' => $this->blankToNull($validated['rosterPatternId'] ?? null),
            'attendance_shift_template_id' => (int) $validated['rosterShiftTemplateId'],
            'attendance_policy_group_id' => (int) $validated['rosterPolicyGroupId'],
            'effective_from' => $validated['rosterEffectiveFrom'],
            'effective_to' => $this->blankToNull($validated['rosterEffectiveTo'] ?? null),
            'publish_state' => $validated['rosterPublishState'],
            'lock_state' => 'open',
            'revision' => 1,
            'exceptions' => [],
            'metadata' => ['created_from' => 'attendance_roster_builder'],
        ]);

        $this->resetForm();
        session()->flash('success', __('Roster assignment saved. It will be used when attendance days are resolved for the covered dates.'));
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

    public function render(): View
    {
        $companyId = $this->companyId();
        $schemaReady = $this->schemaReady();

        return view('livewire.people.attendance.rosters', [
            'schemaReady' => $schemaReady,
            'canManage' => $this->canAttendance('people.attendance.manage'),
            'employees' => $schemaReady
                ? Employee::query()
                    ->where('company_id', $companyId)
                    ->where('status', 'active')
                    ->orderBy('full_name')
                    ->limit(100)
                    ->get()
                : collect(),
            'shiftTemplates' => $schemaReady
                ? AttendanceShiftTemplate::query()
                    ->where('company_id', $companyId)
                    ->orderBy('code')
                    ->get()
                : collect(),
            'policyGroups' => $schemaReady
                ? AttendancePolicyGroup::query()
                    ->where('company_id', $companyId)
                    ->orderBy('code')
                    ->get()
                : collect(),
            'rosterPatterns' => $schemaReady
                ? AttendanceRosterPattern::query()
                    ->where('company_id', $companyId)
                    ->orderBy('code')
                    ->get()
                : collect(),
            'rosterAssignments' => $schemaReady
                ? AttendanceRosterAssignment::query()
                    ->where('company_id', $companyId)
                    ->with(['employee', 'shiftTemplate', 'policyGroup', 'rosterPattern'])
                    ->latest('effective_from')
                    ->limit(40)
                    ->get()
                : collect(),
        ]);
    }

    private function hasRosterOverlap(int $employeeId, string $effectiveFrom, ?string $effectiveTo): bool
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
            ->exists();
    }

    private function resetForm(): void
    {
        $this->rosterEmployeeId = '';
        $this->rosterPatternId = '';
        $this->rosterShiftTemplateId = '';
        $this->rosterPolicyGroupId = '';
        $this->rosterEffectiveFrom = now()->toDateString();
        $this->rosterEffectiveTo = '';
        $this->rosterPublishState = 'draft';
    }
}
