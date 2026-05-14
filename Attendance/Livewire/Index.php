<?php

namespace App\Modules\People\Attendance\Livewire;

use App\Base\Authz\Contracts\AuthorizationService;
use App\Base\Authz\DTO\Actor;
use App\Modules\Core\Company\Models\Company;
use App\Modules\Core\Employee\Models\Employee;
use App\Modules\People\Attendance\Models\AttendanceAbsenceBatch;
use App\Modules\People\Attendance\Models\AttendanceAllowanceRule;
use App\Modules\People\Attendance\Models\AttendanceClockEvent;
use App\Modules\People\Attendance\Models\AttendanceDay;
use App\Modules\People\Attendance\Models\AttendanceGeofence;
use App\Modules\People\Attendance\Models\AttendanceGeofenceGroup;
use App\Modules\People\Attendance\Models\AttendanceOvertimeRequest;
use App\Modules\People\Attendance\Models\AttendancePolicyGroup;
use App\Modules\People\Attendance\Models\AttendanceRosterAssignment;
use App\Modules\People\Attendance\Models\AttendanceRosterPattern;
use App\Modules\People\Attendance\Models\AttendanceShiftTemplate;
use App\Modules\People\Attendance\Services\AttendanceDayResolverService;
use App\Modules\People\Attendance\Services\AttendanceLifecycleService;
use App\Modules\People\Attendance\Services\AttendanceOvertimeService;
use App\Modules\People\Attendance\Services\AttendancePolicySimulationService;
use App\Modules\People\Attendance\Services\AttendancePolicyValidationService;
use App\Modules\People\Attendance\Services\ClockEventIngestionService;
use App\Modules\People\Payroll\Models\PayrollPayItem;
use DateTimeImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;
use Livewire\Component;

class Index extends Component
{
    public string $surface = 'my';

    public string $section = 'policies';

    public string $search = '';

    public string $status = '';

    public bool $showOvertimeModal = false;

    public string $overtimeDate = '';

    public string $overtimeStartsAt = '';

    public string $overtimeEndsAt = '';

    public string $overtimeRequestedHours = '1.00';

    public string $overtimeReason = '';

    public string $decisionReason = '';

    public string $policyPreviewPolicyId = '';

    public string $policyPreviewShiftId = '';

    public string $policyPreviewDate = '';

    public string $policyPreviewClockIn = '08:00';

    public string $policyPreviewClockOut = '17:00';

    /** @var array<string, mixed>|null */
    public ?array $policyValidationResult = null;

    /** @var array<string, mixed>|null */
    public ?array $policySimulationResult = null;

    public string $policyStudioMode = 'library';

    public string $policyTemplateImportJson = '';

    public string $policyTemplateExportJson = '';

    public bool $showPolicyBuilderForm = false;

    public bool $showPolicyTemplateImportModal = false;

    public bool $showAllPolicyTemplates = true;

    public string $selectedPolicyTemplateKey = '';

    public ?int $editingPolicyGroupId = null;

    public string $policyCode = '';

    public string $policyName = '';

    public string $policyEffectiveFrom = '';

    public string $policyEffectiveTo = '';

    public string $policyStatus = AttendancePolicyGroup::STATUS_ACTIVE;

    public string $policyCurrency = 'MYR';

    public string $policyWorkRoundingMethod = 'nearest';

    public string $policyWorkRoundingMinutes = '15';

    public string $policyLatenessRoundingMethod = 'ceiling';

    public string $policyLatenessRoundingMinutes = '5';

    public string $policyGraceIn = '0';

    public string $policyGraceOut = '0';

    public string $policyGraceStartBreak = '0';

    public string $policyGraceEndBreak = '0';

    public bool $policyExcludeBreakFromWork = true;

    public bool $policyLessBreakLateness = true;

    public bool $policyEarlyOvertimeEnabled = true;

    public string $policyEarlyOvertimeMinimumMinutes = '60';

    public bool $policyLateOvertimeEnabled = true;

    public string $policyLateOvertimeMinimumMinutes = '60';

    public bool $policyNormalDayOvertime = true;

    public bool $policyRestDayOvertime = true;

    public bool $policyHolidayOvertime = true;

    public bool $policyOffDayOvertime = true;

    public bool $policyKnockOffLateness = true;

    public bool $policyKnockOffNpl = true;

    public string $policyNormalOvertimePayItem = 'overtime';

    public string $policyExtendedOvertimePayItem = 'overtime_extended';

    public string $policyRestDayOvertimePayItem = 'rest_day_overtime';

    public string $policyHolidayOvertimePayItem = 'holiday_overtime';

    public string $policyLatenessPayItem = 'lateness_deduction';

    public string $policyLatenessMonthlyRoundingMethod = 'ceiling';

    public string $policyLatenessMonthlyRoundingMinutes = '15';

    public string $allowancePolicyGroupId = '';

    public string $allowanceCode = '';

    public string $allowanceName = '';

    public string $allowanceType = AttendanceAllowanceRule::TYPE_DAILY;

    public string $allowancePayItemCode = '';

    public string $allowanceAmount = '0.00';

    public string $allowanceResolutionMethod = AttendanceAllowanceRule::RESOLUTION_SUM;

    public string $allowanceConditionPreset = 'always';

    public string $allowanceMinWorkedMinutes = '480';

    public string $allowanceClockOutAfter = '';

    public string $allowanceClockOutBefore = '';

    public string $allowanceEffectiveFrom = '';

    public string $allowanceStatus = 'active';

    public ?int $editingAllowanceRuleId = null;

    public string $rosterEmployeeId = '';

    public string $rosterPatternId = '';

    public string $rosterShiftTemplateId = '';

    public string $rosterPolicyGroupId = '';

    public string $rosterEffectiveFrom = '';

    public string $rosterEffectiveTo = '';

    public string $rosterPublishState = 'draft';

    public function mount(?string $surface = null, ?string $section = null, ?string $mode = null): void
    {
        $this->surface = in_array($surface, ['my', 'approvals', 'operations', 'settings'], true) ? $surface : 'my';
        $this->section = in_array($section, ['policies', 'shifts', 'rosters', 'allowances', 'locations'], true) ? $section : 'policies';
        $this->policyStudioMode = in_array($mode, ['library', 'builder', 'simulate'], true) ? $mode : 'library';
        $this->overtimeDate = now()->toDateString();
        $this->overtimeStartsAt = now()->setTime(17, 0)->format('H:i');
        $this->overtimeEndsAt = now()->setTime(18, 0)->format('H:i');
        $this->policyPreviewDate = now()->toDateString();
        $this->policyEffectiveFrom = now()->toDateString();
        $this->allowanceEffectiveFrom = now()->toDateString();
        $this->rosterEffectiveFrom = now()->toDateString();
    }

    public function updatedSearch(): void
    {
        $this->search = trim($this->search);
    }

    public function clock(string $eventType): void
    {
        if (! $this->ensureSchemaReady()) {
            return;
        }

        if (! in_array($eventType, [AttendanceClockEvent::TYPE_IN, AttendanceClockEvent::TYPE_OUT], true)) {
            return;
        }

        app(AuthorizationService::class)->authorize(
            Actor::forUser(Auth::user()),
            'people.attendance.execute',
        );

        $employeeId = $this->currentEmployeeId();
        if ($employeeId === null) {
            session()->flash('error', __('Your user account is not linked to an employee record.'));

            return;
        }

        $employee = Employee::query()
            ->where('company_id', $this->companyId())
            ->findOrFail($employeeId);

        app(ClockEventIngestionService::class)->recordWebClock(
            employee: $employee,
            eventType: $eventType,
            actorUserId: (int) Auth::id(),
            ipAddress: request()->ip(),
            timezone: config('app.timezone'),
        );

        session()->flash('success', __('Clock event recorded.'));
    }

    public function openOvertimeModal(): void
    {
        if (! $this->ensureSchemaReady()) {
            return;
        }

        $this->resetValidation();
        $this->showOvertimeModal = true;
    }

    public function submitOvertimeRequest(): void
    {
        if (! $this->ensureSchemaReady()) {
            return;
        }

        app(AuthorizationService::class)->authorize(
            Actor::forUser(Auth::user()),
            'people.attendance.execute',
        );

        $employeeId = $this->currentEmployeeId();
        if ($employeeId === null) {
            session()->flash('error', __('Your user account is not linked to an employee record.'));

            return;
        }

        $validated = $this->validate([
            'overtimeDate' => ['required', 'date'],
            'overtimeStartsAt' => ['required', 'date_format:H:i'],
            'overtimeEndsAt' => ['required', 'date_format:H:i'],
            'overtimeRequestedHours' => ['required', 'numeric', 'min:0.25', 'max:24'],
            'overtimeReason' => ['nullable', 'string', 'max:500'],
        ]);

        $employee = Employee::query()
            ->where('company_id', $this->companyId())
            ->findOrFail($employeeId);
        $day = app(AttendanceDayResolverService::class)->resolve($employee, $validated['overtimeDate']);
        $startsAt = new DateTimeImmutable($validated['overtimeDate'].' '.$validated['overtimeStartsAt']);
        $endsAt = new DateTimeImmutable($validated['overtimeDate'].' '.$validated['overtimeEndsAt']);
        if ($endsAt <= $startsAt) {
            $endsAt = $endsAt->modify('+1 day');
        }

        $request = AttendanceOvertimeRequest::query()->create([
            'company_id' => $employee->company_id,
            'employee_id' => $employee->id,
            'attendance_day_id' => $day->id,
            'request_mode' => 'post_work_actual',
            'status' => AttendanceOvertimeRequest::STATUS_DRAFT,
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'requested_minutes' => (int) round(((float) $validated['overtimeRequestedHours']) * 60),
            'reason' => $this->blankToNull($validated['overtimeReason'] ?? null),
            'submitted_by_user_id' => Auth::id(),
        ]);

        app(AttendanceOvertimeService::class)->submit($request, (int) Auth::id());

        $this->showOvertimeModal = false;
        $this->overtimeReason = '';
        session()->flash('success', __('Overtime request submitted.'));
    }

    public function approveOvertime(int $requestId): void
    {
        if (! $this->ensureSchemaReady()) {
            return;
        }

        $this->authorizeAttendance('people.attendance.approve');

        $request = $this->overtimeRequest($requestId);
        app(AttendanceOvertimeService::class)->approve($request, decisionReason: $this->blankToNull($this->decisionReason));
        $this->decisionReason = '';

        session()->flash('success', __('Overtime request approved.'));
    }

    public function rejectOvertime(int $requestId): void
    {
        if (! $this->ensureSchemaReady()) {
            return;
        }

        $this->authorizeAttendance('people.attendance.approve');

        $request = $this->overtimeRequest($requestId);
        app(AttendanceOvertimeService::class)->reject($request, $this->blankToNull($this->decisionReason));
        $this->decisionReason = '';

        session()->flash('success', __('Overtime request rejected.'));
    }

    public function queueOvertimePayroll(int $requestId): void
    {
        if (! $this->ensureSchemaReady()) {
            return;
        }

        $this->authorizeAttendance('people.attendance.approve');

        $request = $this->overtimeRequest($requestId);
        $outcome = app(AttendanceOvertimeService::class)->queuePayrollHandoff($request);

        if ($outcome === null) {
            session()->flash('error', __('No payable minutes on this overtime request.'));

            return;
        }

        $messageKey = $outcome->isMaterialized() ? 'success' : 'error';
        $message = match (true) {
            $outcome->isMaterialized() => __('Overtime queued to payroll.'),
            $outcome->isPending() => __('Saved as pending — no open payroll run covers this overtime date.'),
            $outcome->isRejected() => __('Cannot queue: the payroll run for this period is closed.'),
            default => __('Overtime contribution recorded.'),
        };

        session()->flash($messageKey, $message);
    }

    public function finalizeDay(int $dayId): void
    {
        if (! $this->ensureSchemaReady()) {
            return;
        }

        $this->authorizeAttendance('people.attendance.manage');

        app(AttendanceLifecycleService::class)->finalize($this->attendanceDay($dayId));

        session()->flash('success', __('Attendance day finalized.'));
    }

    public function lockDay(int $dayId): void
    {
        if (! $this->ensureSchemaReady()) {
            return;
        }

        $this->authorizeAttendance('people.attendance.manage');

        app(AttendanceLifecycleService::class)->lock($this->attendanceDay($dayId));

        session()->flash('success', __('Attendance day locked.'));
    }

    public function validatePolicyPreview(): void
    {
        if (! $this->ensureSchemaReady()) {
            return;
        }

        $this->authorizeAttendance('people.attendance.manage');

        $policyGroup = $this->selectedPolicyGroup();
        if (! $policyGroup instanceof AttendancePolicyGroup) {
            $this->policyValidationResult = $this->errorResult('policy_required', __('Choose an attendance policy group first.'), 'policyPreviewPolicyId');

            return;
        }

        $this->policyValidationResult = app(AttendancePolicyValidationService::class)->validate($policyGroup);
    }

    public function simulatePolicyPreview(): void
    {
        if (! $this->ensureSchemaReady()) {
            return;
        }

        $this->authorizeAttendance('people.attendance.manage');

        $validated = $this->validate([
            'policyPreviewPolicyId' => ['required', 'integer'],
            'policyPreviewShiftId' => ['required', 'integer'],
            'policyPreviewDate' => ['required', 'date'],
            'policyPreviewClockIn' => ['required', 'date_format:H:i'],
            'policyPreviewClockOut' => ['required', 'date_format:H:i'],
        ]);

        $policyGroup = $this->selectedPolicyGroup();
        $shiftTemplate = $this->selectedShiftTemplate();
        if (! $policyGroup instanceof AttendancePolicyGroup || ! $shiftTemplate instanceof AttendanceShiftTemplate) {
            $this->policySimulationResult = $this->errorResult('preview_selection_invalid', __('Choose a policy group and shift template from this company.'), 'policyPreviewPolicyId');

            return;
        }

        $this->policySimulationResult = app(AttendancePolicySimulationService::class)->simulate(
            $policyGroup,
            $shiftTemplate,
            $validated['policyPreviewDate'],
            $validated['policyPreviewClockIn'],
            $validated['policyPreviewClockOut'],
        );
    }

    public function savePolicyGroup(): void
    {
        if (! $this->ensureSchemaReady()) {
            return;
        }

        $this->authorizeAttendance('people.attendance.manage');

        $companyId = $this->companyId();
        $payItemRules = $this->payrollPayItemValidationRules($companyId);
        $validated = $this->validate([
            'policyCode' => [
                'required', 'string', 'max:60', 'alpha_dash',
                Rule::unique('people_attendance_policy_groups', 'code')->where('company_id', $companyId)->ignore($this->editingPolicyGroupId),
            ],
            'policyName' => ['required', 'string', 'max:120'],
            'policyEffectiveFrom' => ['required', 'date'],
            'policyEffectiveTo' => ['nullable', 'date', 'after_or_equal:policyEffectiveFrom'],
            'policyStatus' => ['required', Rule::in([AttendancePolicyGroup::STATUS_ACTIVE, AttendancePolicyGroup::STATUS_INACTIVE])],
            'policyCurrency' => ['required', 'string', 'size:3'],
            'policyWorkRoundingMethod' => ['required', Rule::in(['none', 'floor', 'ceiling', 'nearest'])],
            'policyWorkRoundingMinutes' => ['required_unless:policyWorkRoundingMethod,none', 'integer', 'min:1', 'max:60'],
            'policyLatenessRoundingMethod' => ['required', Rule::in(['none', 'floor', 'ceiling', 'nearest'])],
            'policyLatenessRoundingMinutes' => ['required_unless:policyLatenessRoundingMethod,none', 'integer', 'min:1', 'max:60'],
            'policyGraceIn' => ['required', 'integer', 'min:0', 'max:240'],
            'policyGraceOut' => ['required', 'integer', 'min:0', 'max:240'],
            'policyGraceStartBreak' => ['required', 'integer', 'min:0', 'max:240'],
            'policyGraceEndBreak' => ['required', 'integer', 'min:0', 'max:240'],
            'policyEarlyOvertimeMinimumMinutes' => ['required', 'integer', 'min:0', 'max:720'],
            'policyLateOvertimeMinimumMinutes' => ['required', 'integer', 'min:0', 'max:720'],
            'policyNormalOvertimePayItem' => ['required', ...$payItemRules],
            'policyExtendedOvertimePayItem' => ['nullable', ...$payItemRules],
            'policyRestDayOvertimePayItem' => ['nullable', ...$payItemRules],
            'policyHolidayOvertimePayItem' => ['nullable', ...$payItemRules],
            'policyLatenessPayItem' => ['required', ...$payItemRules],
            'policyLatenessMonthlyRoundingMethod' => ['required', Rule::in(['none', 'floor', 'ceiling', 'nearest'])],
            'policyLatenessMonthlyRoundingMinutes' => ['required_unless:policyLatenessMonthlyRoundingMethod,none', 'integer', 'min:1', 'max:60'],
        ]);

        $attributes = [
            'company_id' => $companyId,
            'code' => str($validated['policyCode'])->upper()->toString(),
            'name' => $validated['policyName'],
            'effective_from' => $validated['policyEffectiveFrom'],
            'effective_to' => $this->blankToNull($validated['policyEffectiveTo'] ?? null),
            'status' => $validated['policyStatus'],
            'version' => $this->editingPolicyGroupId === null ? 1 : $this->policyGroup($this->editingPolicyGroupId)->version + 1,
            'work_hour_rules' => $this->policyWorkHourRules($validated),
            'lateness_rules' => $this->policyLatenessRules($validated),
            'overtime_rules' => $this->policyOvertimeRules($validated),
            'overtime_export_rules' => $this->policyOvertimeExportRules($validated),
            'lateness_export_rules' => $this->policyLatenessExportRules($validated),
            'payroll_defaults' => ['currency' => strtoupper($validated['policyCurrency'])],
            'metadata' => ['created_from' => 'attendance_policy_builder'],
        ];

        $policyGroup = $this->editingPolicyGroupId === null
            ? AttendancePolicyGroup::query()->create($attributes)
            : tap($this->policyGroup($this->editingPolicyGroupId))->update($attributes);

        $this->policyPreviewPolicyId = (string) $policyGroup->id;
        $this->policyValidationResult = app(AttendancePolicyValidationService::class)->validate($policyGroup->refresh());
        $this->resetPolicyForm();
        $this->policyStudioMode = 'library';
        session()->flash('success', __('Policy group saved and validated.'));
    }

    public function startPolicyBuilder(): void
    {
        $this->resetPolicyForm();
        $this->showPolicyBuilderForm = false;
        $this->showAllPolicyTemplates = true;
        $this->policyStudioMode = 'builder';
    }

    public function editPolicyGroup(int $policyGroupId): void
    {
        if (! $this->ensureSchemaReady()) {
            return;
        }

        $this->authorizeAttendance('people.attendance.manage');

        $policy = $this->policyGroup($policyGroupId);
        $this->editingPolicyGroupId = $policy->id;
        $this->policyCode = $policy->code;
        $this->policyName = $policy->name;
        $this->policyEffectiveFrom = $policy->effective_from?->toDateString() ?? now()->toDateString();
        $this->policyEffectiveTo = $policy->effective_to?->toDateString() ?? '';
        $this->policyStatus = $policy->status;
        $this->policyCurrency = $policy->payroll_defaults['currency'] ?? 'MYR';
        $this->loadPolicyRuleForm($policy);
        $this->showPolicyBuilderForm = true;
        $this->showAllPolicyTemplates = false;
        $this->selectedPolicyTemplateKey = 'saved-policy';
        $this->policyStudioMode = 'builder';
    }

    public function duplicatePolicyGroup(int $policyGroupId): void
    {
        $this->editPolicyGroup($policyGroupId);
        $source = $this->policyGroup($policyGroupId);
        $this->editingPolicyGroupId = null;
        $this->policyCode = $this->uniquePolicyCode($source->code.'_COPY');
        $this->policyName = $source->name.' Copy';
        $this->policyStatus = AttendancePolicyGroup::STATUS_INACTIVE;
        $this->showPolicyBuilderForm = true;
        $this->showAllPolicyTemplates = false;
    }

    public function usePolicyTemplate(string $templateKey): void
    {
        $template = collect($this->policyTemplates())->firstWhere('key', $templateKey);
        if (! is_array($template)) {
            return;
        }

        $this->resetPolicyForm();
        $this->applyPolicyTemplate($template);
        $this->showPolicyBuilderForm = true;
        $this->showAllPolicyTemplates = false;
        $this->selectedPolicyTemplateKey = $templateKey;
        $this->policyStudioMode = 'builder';
    }

    public function importPolicyTemplate(): void
    {
        if (! $this->ensureSchemaReady()) {
            return;
        }

        $this->authorizeAttendance('people.attendance.manage');

        $payload = json_decode($this->policyTemplateImportJson, true);
        if (! is_array($payload)) {
            $this->addError('policyTemplateImportJson', __('Paste a valid JSON policy template.'));

            return;
        }

        $template = array_is_list($payload) ? ($payload[0] ?? null) : $payload;
        if (! is_array($template)) {
            $this->addError('policyTemplateImportJson', __('The JSON must be a template object or an array of template objects.'));

            return;
        }

        $this->resetPolicyForm();
        $this->applyPolicyTemplate($template);
        $this->showPolicyBuilderForm = true;
        $this->showPolicyTemplateImportModal = false;
        $this->showAllPolicyTemplates = false;
        $this->selectedPolicyTemplateKey = 'imported-template';
        $this->policyStudioMode = 'builder';
        session()->flash('success', __('Policy template imported into the builder. Review, validate, then save it as a policy group.'));
    }

    public function exportBuilderPolicyTemplate(): void
    {
        $this->authorizeAttendance('people.attendance.manage');

        $this->policyTemplateExportJson = json_encode($this->policyTemplateFromBuilder(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        session()->flash('success', __('Policy template JSON prepared. Copy it into a country pack or shared template repository when ready.'));
    }

    public function exportPolicyGroupTemplate(int $policyGroupId): void
    {
        if (! $this->ensureSchemaReady()) {
            return;
        }

        $this->authorizeAttendance('people.attendance.manage');

        $policy = $this->policyGroup($policyGroupId);
        $this->editPolicyGroup($policy->id);
        $this->policyTemplateExportJson = json_encode($this->policyTemplateFromBuilder(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        $this->policyStudioMode = 'library';
        session()->flash('success', __('Policy template JSON prepared from :policy.', ['policy' => $policy->code]));
    }

    public function simulatePolicyGroup(int $policyGroupId): void
    {
        $this->policyPreviewPolicyId = (string) $policyGroupId;
        $this->policyStudioMode = 'simulate';
    }

    public function togglePolicyStatus(int $policyGroupId): void
    {
        if (! $this->ensureSchemaReady()) {
            return;
        }

        $this->authorizeAttendance('people.attendance.manage');

        $policy = $this->policyGroup($policyGroupId);
        $policy->update([
            'status' => $policy->status === AttendancePolicyGroup::STATUS_ACTIVE
                ? AttendancePolicyGroup::STATUS_INACTIVE
                : AttendancePolicyGroup::STATUS_ACTIVE,
        ]);

        session()->flash('success', __('Policy status updated.'));
    }

    public function cancelPolicyEdit(): void
    {
        $this->resetPolicyForm();
        $this->showPolicyBuilderForm = false;
        $this->showAllPolicyTemplates = true;
        $this->policyStudioMode = 'library';
    }

    public function deletePolicyGroup(int $policyGroupId): void
    {
        if (! $this->ensureSchemaReady()) {
            return;
        }

        $this->authorizeAttendance('people.attendance.manage');

        $this->policyGroup($policyGroupId)->delete();
        if ((string) $policyGroupId === $this->policyPreviewPolicyId) {
            $this->policyPreviewPolicyId = '';
            $this->policyValidationResult = null;
            $this->policySimulationResult = null;
        }
        if ($this->editingPolicyGroupId === $policyGroupId) {
            $this->resetPolicyForm();
        }

        session()->flash('success', __('Policy group deleted.'));
    }

    public function saveAllowanceRule(): void
    {
        if (! $this->ensureSchemaReady()) {
            return;
        }

        $this->authorizeAttendance('people.attendance.manage');

        $companyId = $this->companyId();
        $validated = $this->validate([
            'allowancePolicyGroupId' => ['nullable', 'integer'],
            'allowanceCode' => [
                'required',
                'string',
                'max:60',
                'alpha_dash',
                Rule::unique('people_attendance_allowance_rules', 'code')
                    ->where('company_id', $companyId)
                    ->ignore($this->editingAllowanceRuleId),
            ],
            'allowanceName' => ['required', 'string', 'max:120'],
            'allowanceType' => ['required', Rule::in([AttendanceAllowanceRule::TYPE_DAILY, AttendanceAllowanceRule::TYPE_MONTHLY])],
            'allowancePayItemCode' => ['nullable', 'string', 'max:80'],
            'allowanceAmount' => ['required', 'numeric', 'min:0.01'],
            'allowanceResolutionMethod' => ['required', Rule::in([
                AttendanceAllowanceRule::RESOLUTION_SUM,
                AttendanceAllowanceRule::RESOLUTION_MIN,
                AttendanceAllowanceRule::RESOLUTION_MAX,
            ])],
            'allowanceConditionPreset' => ['required', Rule::in(['always', 'min_worked', 'clock_out_after', 'clock_out_window', 'min_worked_and_after'])],
            'allowanceMinWorkedMinutes' => ['nullable', 'required_if:allowanceConditionPreset,min_worked,min_worked_and_after', 'integer', 'min:0', 'max:1440'],
            'allowanceClockOutAfter' => ['nullable', 'required_if:allowanceConditionPreset,clock_out_after,clock_out_window,min_worked_and_after', 'date_format:H:i'],
            'allowanceClockOutBefore' => ['nullable', 'required_if:allowanceConditionPreset,clock_out_window', 'date_format:H:i'],
            'allowanceEffectiveFrom' => ['required', 'date'],
            'allowanceStatus' => ['required', Rule::in(['active', 'inactive'])],
        ]);

        $policyGroupId = $this->blankToNull($validated['allowancePolicyGroupId']);
        if ($policyGroupId !== null) {
            $policyGroupId = AttendancePolicyGroup::query()
                ->where('company_id', $companyId)
                ->findOrFail((int) $policyGroupId)
                ->id;
        }

        $attributes = [
            'company_id' => $companyId,
            'attendance_policy_group_id' => $policyGroupId,
            'code' => str($validated['allowanceCode'])->upper()->toString(),
            'name' => $validated['allowanceName'],
            'allowance_type' => $validated['allowanceType'],
            'payroll_pay_item_code' => $this->blankToNull($validated['allowancePayItemCode'] ?? null),
            'ceiling_amount' => null,
            'resolution_method' => $validated['allowanceResolutionMethod'],
            'condition_rows' => [$this->allowanceConditionRow($validated)],
            'effective_from' => $validated['allowanceEffectiveFrom'],
            'status' => $validated['allowanceStatus'],
            'source_system' => 'blb-ui',
            'metadata' => ['created_from' => 'attendance_allowance_studio'],
        ];

        if ($this->editingAllowanceRuleId === null) {
            AttendanceAllowanceRule::query()->create($attributes);
        } else {
            $this->allowanceRule($this->editingAllowanceRuleId)->update($attributes);
        }

        $this->resetAllowanceForm();
        session()->flash('success', __('Allowance rule saved. Validate the linked policy in Policy Studio before using it for payroll handoff.'));
    }

    public function editAllowanceRule(int $ruleId): void
    {
        if (! $this->ensureSchemaReady()) {
            return;
        }

        $this->authorizeAttendance('people.attendance.manage');

        $rule = $this->allowanceRule($ruleId);
        $row = $rule->condition_rows[0] ?? [];
        $predicate = is_array($row) && is_array($row['predicate'] ?? null) ? $row['predicate'] : [];

        $this->editingAllowanceRuleId = $rule->id;
        $this->allowancePolicyGroupId = $rule->attendance_policy_group_id === null ? '' : (string) $rule->attendance_policy_group_id;
        $this->allowanceCode = $rule->code;
        $this->allowanceName = $rule->name;
        $this->allowanceType = $rule->allowance_type;
        $this->allowancePayItemCode = $rule->payroll_pay_item_code ?? '';
        $this->allowanceAmount = (string) ($row['amount'] ?? '0.00');
        $this->allowanceResolutionMethod = $rule->resolution_method;
        $this->allowanceConditionPreset = $this->allowancePresetFromPredicate($predicate);
        $this->allowanceMinWorkedMinutes = (string) ($predicate['min_worked_minutes'] ?? '480');
        $this->allowanceClockOutAfter = (string) ($predicate['clock_out_after'] ?? '');
        $this->allowanceClockOutBefore = (string) ($predicate['clock_out_before'] ?? '');
        $this->allowanceEffectiveFrom = $rule->effective_from?->toDateString() ?? now()->toDateString();
        $this->allowanceStatus = $rule->status;
    }

    public function cancelAllowanceEdit(): void
    {
        $this->resetAllowanceForm();
    }

    public function deleteAllowanceRule(int $ruleId): void
    {
        if (! $this->ensureSchemaReady()) {
            return;
        }

        $this->authorizeAttendance('people.attendance.manage');

        $this->allowanceRule($ruleId)->delete();

        if ($this->editingAllowanceRuleId === $ruleId) {
            $this->resetAllowanceForm();
        }

        session()->flash('success', __('Allowance rule deleted.'));
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

        $this->resetRosterForm();
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
        $currentEmployeeId = $this->currentEmployeeId();
        $search = trim($this->search);
        $actor = Actor::forUser(Auth::user());
        $authz = app(AuthorizationService::class);
        $canManage = $authz->can($actor, 'people.attendance.manage')->allowed;
        $canApprove = $authz->can($actor, 'people.attendance.approve')->allowed;
        $canClock = $authz->can($actor, 'people.attendance.execute')->allowed;
        $schemaReady = Schema::hasTable('people_attendance_days');

        [$surfaceTitle, $surfaceSubtitle] = $this->surfaceCopy();

        if (! $schemaReady) {
            return view('livewire.people.attendance.index', $this->emptyViewData(
                surface: $this->surface,
                surfaceTitle: $surfaceTitle,
                surfaceSubtitle: $surfaceSubtitle,
                canManage: $canManage,
                canApprove: $canApprove,
                canClock: $canClock,
                currentEmployeeId: $currentEmployeeId,
            ));
        }

        return view('livewire.people.attendance.index', [
            'surface' => $this->surface,
            'section' => $this->section,
            'surfaceTitle' => $surfaceTitle,
            'surfaceSubtitle' => $surfaceSubtitle,
            'schemaReady' => true,
            'canManage' => $canManage,
            'canApprove' => $canApprove,
            'canClock' => $canClock,
            'currentEmployeeId' => $currentEmployeeId,
            'policyTemplates' => $this->policyTemplates(),
            'payrollPayItems' => $this->payrollPayItems($companyId),
            'employees' => Employee::query()
                ->where('company_id', $companyId)
                ->where('status', 'active')
                ->orderBy('full_name')
                ->limit(100)
                ->get(),
            'attendanceDays' => AttendanceDay::query()
                ->where('company_id', $companyId)
                ->with(['employee', 'shiftTemplate', 'policyGroup'])
                ->when($this->surface === 'my' && $currentEmployeeId !== null, fn ($query) => $query->where('employee_id', $currentEmployeeId))
                ->when($this->status !== '', fn ($query) => $query->where('status', $this->status))
                ->when($search !== '', fn ($query) => $query->whereHas('employee', fn ($employeeQuery) => $employeeQuery
                    ->where('employee_number', 'like', "%{$search}%")
                    ->orWhere('full_name', 'like', "%{$search}%")))
                ->latest('attendance_date')
                ->limit(80)
                ->get(),
            'pendingOvertime' => AttendanceOvertimeRequest::query()
                ->where('company_id', $companyId)
                ->where('status', AttendanceOvertimeRequest::STATUS_SUBMITTED)
                ->with(['employee', 'attendanceDay'])
                ->latest('submitted_at')
                ->limit(40)
                ->get(),
            'overtimeRequests' => AttendanceOvertimeRequest::query()
                ->where('company_id', $companyId)
                ->whereIn('status', [
                    AttendanceOvertimeRequest::STATUS_SUBMITTED,
                    AttendanceOvertimeRequest::STATUS_APPROVED,
                    AttendanceOvertimeRequest::STATUS_QUEUED_FOR_PAYROLL,
                ])
                ->with(['employee', 'attendanceDay'])
                ->latest('submitted_at')
                ->limit(60)
                ->get(),
            'clockEvents' => AttendanceClockEvent::query()
                ->where('company_id', $companyId)
                ->with(['employee'])
                ->latest('occurred_at')
                ->limit(40)
                ->get(),
            'absenceBatches' => AttendanceAbsenceBatch::query()
                ->where('company_id', $companyId)
                ->withCount('entries')
                ->latest('period_starts_on')
                ->limit(20)
                ->get(),
            'shiftTemplates' => AttendanceShiftTemplate::query()
                ->where('company_id', $companyId)
                ->with('punchWindows')
                ->orderBy('code')
                ->get(),
            'policyGroups' => AttendancePolicyGroup::query()
                ->where('company_id', $companyId)
                ->with('allowanceRules')
                ->orderBy('code')
                ->get(),
            'allowanceRules' => AttendanceAllowanceRule::query()
                ->where('company_id', $companyId)
                ->with('policyGroup')
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
            'geofences' => AttendanceGeofence::query()
                ->where('company_id', $companyId)
                ->orderBy('code')
                ->get(),
            'geofenceGroups' => AttendanceGeofenceGroup::query()
                ->where('company_id', $companyId)
                ->with('fences')
                ->orderBy('code')
                ->get(),
            'statusOptions' => $this->statusOptions(),
        ]);
    }

    public function statusVariant(string $status): string
    {
        return match ($status) {
            AttendanceDay::STATUS_READY_FOR_REVIEW, AttendanceDay::STATUS_FINALIZED, AttendanceDay::STATUS_EXPORTED_TO_PAYROLL => 'success',
            AttendanceDay::STATUS_EXCEPTION_PENDING, AttendanceDay::STATUS_IN_PROGRESS => 'warning',
            AttendanceDay::STATUS_LOCKED => 'danger',
            default => 'info',
        };
    }

    /** @return array<string, string> */
    private function statusOptions(): array
    {
        return [
            AttendanceDay::STATUS_SCHEDULED => __('Scheduled'),
            AttendanceDay::STATUS_IN_PROGRESS => __('In progress'),
            AttendanceDay::STATUS_EXCEPTION_PENDING => __('Exception pending'),
            AttendanceDay::STATUS_READY_FOR_REVIEW => __('Ready for review'),
            AttendanceDay::STATUS_FINALIZED => __('Finalized'),
            AttendanceDay::STATUS_EXPORTED_TO_PAYROLL => __('Exported to payroll'),
            AttendanceDay::STATUS_LOCKED => __('Locked'),
        ];
    }

    private function companyId(): int
    {
        return auth()->user()?->company_id ?? Company::LICENSEE_ID;
    }

    private function currentEmployeeId(): ?int
    {
        $id = auth()->user()?->employee_id;

        return $id === null ? null : (int) $id;
    }

    private function authorizeAttendance(string $capability): void
    {
        app(AuthorizationService::class)->authorize(
            Actor::forUser(Auth::user()),
            $capability,
        );
    }

    private function attendanceDay(int $dayId): AttendanceDay
    {
        return AttendanceDay::query()
            ->where('company_id', $this->companyId())
            ->findOrFail($dayId);
    }

    private function overtimeRequest(int $requestId): AttendanceOvertimeRequest
    {
        return AttendanceOvertimeRequest::query()
            ->where('company_id', $this->companyId())
            ->findOrFail($requestId);
    }

    private function allowanceRule(int $ruleId): AttendanceAllowanceRule
    {
        return AttendanceAllowanceRule::query()
            ->where('company_id', $this->companyId())
            ->findOrFail($ruleId);
    }

    private function policyGroup(int $policyGroupId): AttendancePolicyGroup
    {
        return AttendancePolicyGroup::query()
            ->where('company_id', $this->companyId())
            ->findOrFail($policyGroupId);
    }

    private function selectedPolicyGroup(): ?AttendancePolicyGroup
    {
        if ($this->policyPreviewPolicyId === '') {
            return null;
        }

        return AttendancePolicyGroup::query()
            ->where('company_id', $this->companyId())
            ->find((int) $this->policyPreviewPolicyId);
    }

    private function selectedShiftTemplate(): ?AttendanceShiftTemplate
    {
        if ($this->policyPreviewShiftId === '') {
            return null;
        }

        return AttendanceShiftTemplate::query()
            ->where('company_id', $this->companyId())
            ->find((int) $this->policyPreviewShiftId);
    }

    /** @param array<string, mixed> $validated */
    private function policyWorkHourRules(array $validated): array
    {
        return [
            'daily_rounding' => $this->roundingRule($validated['policyWorkRoundingMethod'], $validated['policyWorkRoundingMinutes']),
            'daily_rated_workday_counts' => ['paid_rest_day' => false, 'paid_off_day' => false, 'paid_holiday' => false],
            'break_treatment' => [
                'monthly_exclude_break_hours' => $this->policyExcludeBreakFromWork,
                'daily_exclude_break_hours' => $this->policyExcludeBreakFromWork,
                'less_break_lateness' => $this->policyLessBreakLateness,
            ],
        ];
    }

    /** @param array<string, mixed> $validated */
    private function policyLatenessRules(array $validated): array
    {
        return [
            'daily_rounding' => $this->roundingRule($validated['policyLatenessRoundingMethod'], $validated['policyLatenessRoundingMinutes']),
            'grace' => [
                'in' => (int) $validated['policyGraceIn'],
                'out' => (int) $validated['policyGraceOut'],
                'start_break' => (int) $validated['policyGraceStartBreak'],
                'end_break' => (int) $validated['policyGraceEndBreak'],
            ],
        ];
    }

    /** @param array<string, mixed> $validated */
    private function policyOvertimeRules(array $validated): array
    {
        return [
            'early_ot' => ['enabled' => $this->policyEarlyOvertimeEnabled, 'minimum_minutes' => (int) $validated['policyEarlyOvertimeMinimumMinutes']],
            'late_ot' => ['enabled' => $this->policyLateOvertimeEnabled, 'minimum_minutes' => (int) $validated['policyLateOvertimeMinimumMinutes']],
            'day_types' => [
                'normal' => $this->policyNormalDayOvertime,
                'holiday' => $this->policyHolidayOvertime,
                'rest_day' => $this->policyRestDayOvertime,
                'off_day' => $this->policyOffDayOvertime,
            ],
            'adjustment_bands' => [['from' => 0, 'to' => 60, 'operation' => 'set', 'minutes' => 0, 'day_types' => ['normal']]],
            'knock_off' => ['lateness' => $this->policyKnockOffLateness, 'npl' => $this->policyKnockOffNpl],
        ];
    }

    /** @param array<string, mixed> $validated */
    private function policyOvertimeExportRules(array $validated): array
    {
        return [
            'normal' => array_values(array_filter([
                ['lte_hours' => 2, 'pay_item_code' => $validated['policyNormalOvertimePayItem']],
                $this->blankToNull($validated['policyExtendedOvertimePayItem'] ?? null) === null ? null : ['lte_hours' => null, 'pay_item_code' => $validated['policyExtendedOvertimePayItem']],
            ])),
            'rest_day' => $this->blankToNull($validated['policyRestDayOvertimePayItem'] ?? null) === null ? [] : [['lte_hours' => null, 'pay_item_code' => $validated['policyRestDayOvertimePayItem']]],
            'holiday' => $this->blankToNull($validated['policyHolidayOvertimePayItem'] ?? null) === null ? [] : [['lte_hours' => null, 'pay_item_code' => $validated['policyHolidayOvertimePayItem']]],
        ];
    }

    /** @param array<string, mixed> $validated */
    private function policyLatenessExportRules(array $validated): array
    {
        return [
            'monthly_rounding' => $this->roundingRule($validated['policyLatenessMonthlyRoundingMethod'], $validated['policyLatenessMonthlyRoundingMinutes']),
            'pay_item_code' => $validated['policyLatenessPayItem'],
        ];
    }

    private function roundingRule(string $method, mixed $minutes): array
    {
        return ['method' => $method, 'minutes' => $method === 'none' ? null : (int) $minutes];
    }

    private function loadPolicyRuleForm(AttendancePolicyGroup $policy): void
    {
        $work = $policy->work_hour_rules ?? [];
        $lateness = $policy->lateness_rules ?? [];
        $overtime = $policy->overtime_rules ?? [];
        $overtimeExport = $policy->overtime_export_rules ?? [];
        $latenessExport = $policy->lateness_export_rules ?? [];
        $this->policyWorkRoundingMethod = $work['daily_rounding']['method'] ?? 'nearest';
        $this->policyWorkRoundingMinutes = (string) ($work['daily_rounding']['minutes'] ?? 15);
        $this->policyLatenessRoundingMethod = $lateness['daily_rounding']['method'] ?? 'ceiling';
        $this->policyLatenessRoundingMinutes = (string) ($lateness['daily_rounding']['minutes'] ?? 5);
        $this->policyGraceIn = (string) ($lateness['grace']['in'] ?? 0);
        $this->policyGraceOut = (string) ($lateness['grace']['out'] ?? 0);
        $this->policyGraceStartBreak = (string) ($lateness['grace']['start_break'] ?? 0);
        $this->policyGraceEndBreak = (string) ($lateness['grace']['end_break'] ?? 0);
        $this->policyExcludeBreakFromWork = (bool) ($work['break_treatment']['daily_exclude_break_hours'] ?? true);
        $this->policyLessBreakLateness = (bool) ($work['break_treatment']['less_break_lateness'] ?? true);
        $this->policyEarlyOvertimeEnabled = (bool) ($overtime['early_ot']['enabled'] ?? true);
        $this->policyEarlyOvertimeMinimumMinutes = (string) ($overtime['early_ot']['minimum_minutes'] ?? 60);
        $this->policyLateOvertimeEnabled = (bool) ($overtime['late_ot']['enabled'] ?? true);
        $this->policyLateOvertimeMinimumMinutes = (string) ($overtime['late_ot']['minimum_minutes'] ?? 60);
        $this->policyNormalOvertimePayItem = $overtimeExport['normal'][0]['pay_item_code'] ?? 'overtime';
        $this->policyExtendedOvertimePayItem = $overtimeExport['normal'][1]['pay_item_code'] ?? 'overtime_extended';
        $this->policyRestDayOvertimePayItem = $overtimeExport['rest_day'][0]['pay_item_code'] ?? 'rest_day_overtime';
        $this->policyHolidayOvertimePayItem = $overtimeExport['holiday'][0]['pay_item_code'] ?? 'holiday_overtime';
        $this->policyLatenessPayItem = $latenessExport['pay_item_code'] ?? 'lateness_deduction';
        $this->policyLatenessMonthlyRoundingMethod = $latenessExport['monthly_rounding']['method'] ?? 'ceiling';
        $this->policyLatenessMonthlyRoundingMinutes = (string) ($latenessExport['monthly_rounding']['minutes'] ?? 15);
    }

    private function resetPolicyForm(): void
    {
        $this->editingPolicyGroupId = null;
        $this->policyCode = '';
        $this->policyName = '';
        $this->policyEffectiveFrom = now()->toDateString();
        $this->policyEffectiveTo = '';
        $this->policyStatus = AttendancePolicyGroup::STATUS_ACTIVE;
        $this->policyCurrency = 'MYR';
        $this->selectedPolicyTemplateKey = '';
        $this->loadPolicyRuleForm(new AttendancePolicyGroup);
    }

    /** @return list<array<string, mixed>> */
    private function policyTemplates(): array
    {
        return [
            [
                'schema' => 'belimbing.attendance.policy-template.v1',
                'key' => 'standard-production',
                'code' => 'PROD_8_5',
                'name' => __('Production 8 to 5'),
                'summary' => __('Strict clocking, 5-minute lateness rounding, overtime after 60 minutes.'),
                'best_for' => __('Factories, warehouses, and fixed-shift teams.'),
                'work_rounding_method' => 'nearest',
                'work_rounding_minutes' => 15,
                'lateness_rounding_method' => 'ceiling',
                'lateness_rounding_minutes' => 5,
                'grace_in' => 0,
                'early_ot_minimum' => 60,
                'late_ot_minimum' => 60,
                'normal_ot_pay_item' => 'overtime',
                'lateness_pay_item' => 'lateness_deduction',
            ],
            [
                'schema' => 'belimbing.attendance.policy-template.v1',
                'key' => 'office-grace',
                'code' => 'OFFICE_GRACE',
                'name' => __('Office with grace period'),
                'summary' => __('Gentler office policy with 10-minute clock-in grace and simple overtime mapping.'),
                'best_for' => __('Administrative teams and lower-risk attendance tracking.'),
                'work_rounding_method' => 'nearest',
                'work_rounding_minutes' => 15,
                'lateness_rounding_method' => 'ceiling',
                'lateness_rounding_minutes' => 5,
                'grace_in' => 10,
                'early_ot_minimum' => 60,
                'late_ot_minimum' => 60,
                'normal_ot_pay_item' => 'overtime',
                'lateness_pay_item' => 'lateness_deduction',
            ],
            [
                'schema' => 'belimbing.attendance.policy-template.v1',
                'key' => 'night-operations',
                'code' => 'NIGHT_OPS',
                'name' => __('Night operations'),
                'summary' => __('Fixed-shift policy prepared for night-shift rosters and allowance testing.'),
                'best_for' => __('Security, operations, and production teams with late clock-out patterns.'),
                'work_rounding_method' => 'nearest',
                'work_rounding_minutes' => 15,
                'lateness_rounding_method' => 'ceiling',
                'lateness_rounding_minutes' => 5,
                'grace_in' => 0,
                'early_ot_minimum' => 30,
                'late_ot_minimum' => 30,
                'normal_ot_pay_item' => 'night_overtime',
                'lateness_pay_item' => 'lateness_deduction',
            ],
        ];
    }

    /** @param array<string, mixed> $template */
    private function applyPolicyTemplate(array $template): void
    {
        $this->policyCode = $this->uniquePolicyCode((string) ($template['code'] ?? 'POLICY'));
        $this->policyName = (string) ($template['name'] ?? __('Imported policy'));
        $this->policyWorkRoundingMethod = (string) ($template['work_rounding_method'] ?? $this->policyWorkRoundingMethod);
        $this->policyWorkRoundingMinutes = (string) ($template['work_rounding_minutes'] ?? $this->policyWorkRoundingMinutes);
        $this->policyLatenessRoundingMethod = (string) ($template['lateness_rounding_method'] ?? $this->policyLatenessRoundingMethod);
        $this->policyLatenessRoundingMinutes = (string) ($template['lateness_rounding_minutes'] ?? $this->policyLatenessRoundingMinutes);
        $this->policyGraceIn = (string) ($template['grace_in'] ?? $this->policyGraceIn);
        $this->policyGraceOut = (string) ($template['grace_out'] ?? $this->policyGraceOut);
        $this->policyGraceStartBreak = (string) ($template['grace_start_break'] ?? $this->policyGraceStartBreak);
        $this->policyGraceEndBreak = (string) ($template['grace_end_break'] ?? $this->policyGraceEndBreak);
        $this->policyEarlyOvertimeMinimumMinutes = (string) ($template['early_ot_minimum'] ?? $this->policyEarlyOvertimeMinimumMinutes);
        $this->policyLateOvertimeMinimumMinutes = (string) ($template['late_ot_minimum'] ?? $this->policyLateOvertimeMinimumMinutes);
        $this->policyNormalOvertimePayItem = (string) ($template['normal_ot_pay_item'] ?? $this->policyNormalOvertimePayItem);
        $this->policyExtendedOvertimePayItem = (string) ($template['extended_ot_pay_item'] ?? $this->policyExtendedOvertimePayItem);
        $this->policyRestDayOvertimePayItem = (string) ($template['rest_day_ot_pay_item'] ?? $this->policyRestDayOvertimePayItem);
        $this->policyHolidayOvertimePayItem = (string) ($template['holiday_ot_pay_item'] ?? $this->policyHolidayOvertimePayItem);
        $this->policyLatenessPayItem = (string) ($template['lateness_pay_item'] ?? $this->policyLatenessPayItem);
        $this->policyCurrency = strtoupper((string) ($template['currency'] ?? $this->policyCurrency));
    }

    /** @return array<string, mixed> */
    private function policyTemplateFromBuilder(): array
    {
        return [
            'schema' => 'belimbing.attendance.policy-template.v1',
            'code' => str($this->policyCode)->upper()->toString(),
            'name' => $this->policyName,
            'summary' => __('Exported from Policy Studio.'),
            'best_for' => __('Use as a reviewed starting point for similar teams.'),
            'currency' => strtoupper($this->policyCurrency),
            'work_rounding_method' => $this->policyWorkRoundingMethod,
            'work_rounding_minutes' => (int) $this->policyWorkRoundingMinutes,
            'lateness_rounding_method' => $this->policyLatenessRoundingMethod,
            'lateness_rounding_minutes' => (int) $this->policyLatenessRoundingMinutes,
            'grace_in' => (int) $this->policyGraceIn,
            'grace_out' => (int) $this->policyGraceOut,
            'grace_start_break' => (int) $this->policyGraceStartBreak,
            'grace_end_break' => (int) $this->policyGraceEndBreak,
            'early_ot_minimum' => (int) $this->policyEarlyOvertimeMinimumMinutes,
            'late_ot_minimum' => (int) $this->policyLateOvertimeMinimumMinutes,
            'normal_ot_pay_item' => $this->policyNormalOvertimePayItem,
            'extended_ot_pay_item' => $this->policyExtendedOvertimePayItem,
            'rest_day_ot_pay_item' => $this->policyRestDayOvertimePayItem,
            'holiday_ot_pay_item' => $this->policyHolidayOvertimePayItem,
            'lateness_pay_item' => $this->policyLatenessPayItem,
        ];
    }

    private function uniquePolicyCode(string $baseCode): string
    {
        $baseCode = str($baseCode)->upper()->replaceMatches('/[^A-Z0-9_]+/', '_')->trim('_')->toString() ?: 'POLICY';
        $candidate = $baseCode;
        $suffix = 2;

        while (AttendancePolicyGroup::query()->where('company_id', $this->companyId())->where('code', $candidate)->exists()) {
            $candidate = $baseCode.'_'.$suffix;
            $suffix++;
        }

        return $candidate;
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    private function allowanceConditionRow(array $validated): array
    {
        $predicate = [];
        $preset = $validated['allowanceConditionPreset'];

        if (in_array($preset, ['min_worked', 'min_worked_and_after'], true)) {
            $predicate['min_worked_minutes'] = (int) ($validated['allowanceMinWorkedMinutes'] ?: 0);
        }

        if (in_array($preset, ['clock_out_after', 'clock_out_window', 'min_worked_and_after'], true)) {
            $predicate['clock_out_after'] = $validated['allowanceClockOutAfter'];
        }

        if ($preset === 'clock_out_window') {
            $predicate['clock_out_before'] = $validated['allowanceClockOutBefore'];
        }

        return [
            'description' => $this->allowanceConditionDescription($preset),
            'amount' => (float) $validated['allowanceAmount'],
            'predicate' => $predicate,
        ];
    }

    private function allowanceConditionDescription(string $preset): string
    {
        return match ($preset) {
            'min_worked' => 'Pay when worked minutes meet the configured threshold.',
            'clock_out_after' => 'Pay when clock-out is after the configured time.',
            'clock_out_window' => 'Pay when clock-out falls inside the configured time window.',
            'min_worked_and_after' => 'Pay when worked minutes meet the threshold and clock-out is after the configured time.',
            default => 'Pay whenever the linked policy applies.',
        };
    }

    /**
     * @param  array<string, mixed>  $predicate
     */
    private function allowancePresetFromPredicate(array $predicate): string
    {
        $hasMinWorked = array_key_exists('min_worked_minutes', $predicate);
        $hasClockOutAfter = array_key_exists('clock_out_after', $predicate);
        $hasClockOutBefore = array_key_exists('clock_out_before', $predicate);

        return match (true) {
            $hasMinWorked && $hasClockOutAfter => 'min_worked_and_after',
            $hasClockOutAfter && $hasClockOutBefore => 'clock_out_window',
            $hasClockOutAfter => 'clock_out_after',
            $hasMinWorked => 'min_worked',
            default => 'always',
        };
    }

    private function resetAllowanceForm(): void
    {
        $this->editingAllowanceRuleId = null;
        $this->allowancePolicyGroupId = '';
        $this->allowanceCode = '';
        $this->allowanceName = '';
        $this->allowanceType = AttendanceAllowanceRule::TYPE_DAILY;
        $this->allowancePayItemCode = '';
        $this->allowanceAmount = '0.00';
        $this->allowanceResolutionMethod = AttendanceAllowanceRule::RESOLUTION_SUM;
        $this->allowanceConditionPreset = 'always';
        $this->allowanceMinWorkedMinutes = '480';
        $this->allowanceClockOutAfter = '';
        $this->allowanceClockOutBefore = '';
        $this->allowanceEffectiveFrom = now()->toDateString();
        $this->allowanceStatus = 'active';
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

    private function resetRosterForm(): void
    {
        $this->rosterEmployeeId = '';
        $this->rosterPatternId = '';
        $this->rosterShiftTemplateId = '';
        $this->rosterPolicyGroupId = '';
        $this->rosterEffectiveFrom = now()->toDateString();
        $this->rosterEffectiveTo = '';
        $this->rosterPublishState = 'draft';
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function surfaceCopy(): array
    {
        if ($this->surface === 'settings') {
            return match ($this->section) {
                'shifts' => [__('Shift Builder'), __('Maintain reusable shift times, cross-midnight settings, punch windows, breaks, and expected work minutes.')],
                'rosters' => [__('Roster Builder'), __('Assign employees to shifts and policy groups so supervisors can publish clean rosters.')],
                'allowances' => [__('Allowance Rules'), __('Maintain attendance-driven allowance rules and their payroll pay item mappings.')],
                'locations' => [__('Clocking Locations'), __('Maintain geofences and geofence groups used by clock-source policies.')],
                default => match ($this->policyStudioMode) {
                    'builder' => [__('Policy Builder'), __('Start from a template, tune the policy, then validate it before supervisors use it in rosters.')],
                    'simulate' => [__('Policy Validator'), __('Validate policy groups and simulate attendance outcomes before rules affect rosters or payroll.')],
                    default => [__('Policy Library'), __('Start from templates, manage active policy groups, and open validation or builder flows.')],
                },
            };
        }

        return match ($this->surface) {
            'approvals' => [__('Attendance Approvals'), __('Review overtime and attendance exceptions before they affect payroll.')],
            'operations' => [__('Attendance Operations'), __('Review timecards, absenteeism batches, clock events, and payroll handoff readiness.')],
            default => [__('My Attendance'), __('Review your timecard and record web clock events where enabled.')],
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function errorResult(string $code, string $message, string $path): array
    {
        return [
            'status' => 'error',
            'summary' => ['errors' => 1, 'warnings' => 0, 'info' => 0],
            'findings' => [[
                'severity' => 'error',
                'code' => $code,
                'message' => $message,
                'path' => $path,
            ]],
        ];
    }

    private function blankToNull(mixed $value): mixed
    {
        if (is_string($value) && trim($value) === '') {
            return null;
        }

        return $value;
    }

    private function ensureSchemaReady(): bool
    {
        if (Schema::hasTable('people_attendance_days')) {
            return true;
        }

        session()->flash('error', __('Attendance database tables are not installed yet. Run the Attendance migration first.'));

        return false;
    }

    /**
     * @return array<string, mixed>
     */
    private function emptyViewData(
        string $surface,
        string $surfaceTitle,
        string $surfaceSubtitle,
        bool $canManage,
        bool $canApprove,
        bool $canClock,
        ?int $currentEmployeeId,
    ): array {
        /** @var Collection<int, mixed> $empty */
        $empty = collect();

        return [
            'surface' => $surface,
            'surfaceTitle' => $surfaceTitle,
            'surfaceSubtitle' => $surfaceSubtitle,
            'schemaReady' => false,
            'canManage' => $canManage,
            'canApprove' => $canApprove,
            'canClock' => $canClock,
            'currentEmployeeId' => $currentEmployeeId,
            'section' => $this->section,
            'policyTemplates' => [],
            'payrollPayItems' => $empty,
            'employees' => $empty,
            'attendanceDays' => $empty,
            'pendingOvertime' => $empty,
            'overtimeRequests' => $empty,
            'clockEvents' => $empty,
            'absenceBatches' => $empty,
            'shiftTemplates' => $empty,
            'policyGroups' => $empty,
            'allowanceRules' => $empty,
            'rosterPatterns' => $empty,
            'rosterAssignments' => $empty,
            'geofences' => $empty,
            'geofenceGroups' => $empty,
            'statusOptions' => $this->statusOptions(),
        ];
    }

    /**
     * @return Collection<int, PayrollPayItem>
     */
    private function payrollPayItems(int $companyId): Collection
    {
        if (! Schema::hasTable('people_payroll_pay_items')) {
            return collect();
        }

        return PayrollPayItem::query()
            ->where('status', 'active')
            ->where(function ($query) use ($companyId): void {
                $query->where('company_id', $companyId)
                    ->orWhereNull('company_id');
            })
            ->orderBy('code')
            ->get();
    }

    /**
     * @return array<int, mixed>
     */
    private function payrollPayItemValidationRules(int $companyId): array
    {
        $rules = ['string', 'max:80'];

        if ($this->payrollPayItems($companyId)->isEmpty()) {
            return $rules;
        }

        $rules[] = Rule::exists('people_payroll_pay_items', 'code')
            ->where(function ($query) use ($companyId): void {
                $query->where('status', 'active')
                    ->where(function ($scope) use ($companyId): void {
                        $scope->where('company_id', $companyId)
                            ->orWhereNull('company_id');
                    });
            });

        return $rules;
    }
}
