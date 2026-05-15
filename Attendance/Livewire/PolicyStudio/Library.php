<?php

namespace App\Modules\People\Attendance\Livewire\PolicyStudio;

use App\Modules\People\Attendance\Livewire\Concerns\InteractsWithAttendanceScreen;
use App\Modules\People\Attendance\Models\AttendancePolicyGroup;
use App\Modules\People\Attendance\Models\AttendanceShiftTemplate;
use App\Modules\People\Attendance\Services\PolicyTemplateSerializer;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithFileUploads;

/**
 * Combined Policy Library + Builder.
 *
 * Renders the policy groups table by default ($mode === 'list'). Clicking
 * "New", "Edit", or "Duplicate" switches into $mode === 'form' which shows
 * the policy builder inline; save and cancel return to list mode without a
 * page navigation. Simulate still redirects to the dedicated Validator
 * page because that is a separate workflow.
 */
class Library extends Component
{
    use InteractsWithAttendanceScreen;
    use WithFileUploads;

    #[Url(as: 'mode')]
    public string $mode = 'list';

    // === Library state ===

    public string $policyTemplateExportJson = '';

    // === Builder form state ===

    public bool $showPolicyBuilderForm = false;

    public bool $showAllPolicyTemplates = true;

    #[Url(as: 'template')]
    public string $selectedPolicyTemplateKey = '';

    #[Url(as: 'policy')]
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

    public bool $showPolicyTemplateImportModal = false;

    public $policyTemplateUpload = null;

    public function mount(): void
    {
        $selectedPolicyTemplateKey = $this->selectedPolicyTemplateKey;
        $this->policyEffectiveFrom = now()->toDateString();

        if ($this->editingPolicyGroupId !== null) {
            $this->editPolicyGroup($this->editingPolicyGroupId);

            return;
        }

        if ($this->mode === 'form') {
            $this->startNewPolicy();

            if ($selectedPolicyTemplateKey !== '') {
                $this->usePolicyTemplate($selectedPolicyTemplateKey);
            }
        }
    }

    /** Friendly attribute names so validation errors read like field labels. */
    protected function validationAttributes(): array
    {
        return [
            'policyCode' => __('Policy code'),
            'policyName' => __('Policy name'),
            'policyEffectiveFrom' => __('Effective from'),
            'policyEffectiveTo' => __('Effective to'),
            'policyStatus' => __('Status'),
            'policyCurrency' => __('Payroll currency'),
            'policyWorkRoundingMethod' => __('Work rounding'),
            'policyWorkRoundingMinutes' => __('Work rounding block'),
            'policyLatenessRoundingMethod' => __('Lateness rounding'),
            'policyLatenessRoundingMinutes' => __('Lateness rounding block'),
            'policyGraceIn' => __('In grace'),
            'policyGraceOut' => __('Out grace'),
            'policyGraceStartBreak' => __('Break out grace'),
            'policyGraceEndBreak' => __('Break in grace'),
            'policyEarlyOvertimeMinimumMinutes' => __('Before-shift OT minimum'),
            'policyLateOvertimeMinimumMinutes' => __('After-shift OT minimum'),
            'policyNormalOvertimePayItem' => __('Normal OT item'),
            'policyExtendedOvertimePayItem' => __('Extended OT item'),
            'policyRestDayOvertimePayItem' => __('Rest day OT item'),
            'policyHolidayOvertimePayItem' => __('Holiday OT item'),
            'policyLatenessPayItem' => __('Deduction pay item'),
            'policyLatenessMonthlyRoundingMethod' => __('Monthly rounding'),
            'policyLatenessMonthlyRoundingMinutes' => __('Monthly rounding block'),
            'policyTemplateUpload' => __('Template file'),
        ];
    }

    /** Concise message templates that read consistently regardless of the rule. */
    protected function messages(): array
    {
        return [
            'required' => ':attribute is required.',
            'required_unless' => ':attribute is required.',
            'string' => ':attribute must be text.',
            'integer' => ':attribute must be a whole number.',
            'numeric' => ':attribute must be a number.',
            'min.numeric' => ':attribute must be at least :min.',
            'max.numeric' => ':attribute must be at most :max.',
            'min.string' => ':attribute must be at least :min characters.',
            'max.string' => ':attribute must be at most :max characters.',
            'date' => ':attribute must be a date.',
            'date_format' => ':attribute must match :format.',
            'after_or_equal' => ':attribute must be on or after :date.',
            'size' => ':attribute must be exactly :size characters.',
            'alpha_dash' => ':attribute may only contain letters, numbers, dashes and underscores.',
            'in' => ':attribute is not a valid option.',
            'exists' => ':attribute is not a valid option.',
            'unique' => ':attribute is already in use.',
        ];
    }

    // === List-mode actions ===

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

    public function deletePolicyGroup(int $policyGroupId): void
    {
        if (! $this->ensureSchemaReady()) {
            return;
        }

        $this->authorizeAttendance('people.attendance.manage');

        $this->policyGroup($policyGroupId)->delete();

        session()->flash('success', __('Policy group deleted.'));
    }

    public function simulatePolicyGroup(int $policyGroupId)
    {
        return redirect()->route('people.attendance.policy-studio.validator', ['policyGroup' => $policyGroupId]);
    }

    public function exportPolicyGroupTemplate(int $policyGroupId): void
    {
        if (! $this->ensureSchemaReady()) {
            return;
        }

        $this->authorizeAttendance('people.attendance.manage');

        $policy = $this->policyGroup($policyGroupId);
        $serializer = app(PolicyTemplateSerializer::class);
        $this->policyTemplateExportJson = $serializer->toJson($serializer->fromPolicyGroup($policy));

        session()->flash('success', __('Policy template JSON ready to download from :policy.', ['policy' => $policy->code]));
    }

    // === Mode transitions ===

    public function startNewPolicy(): void
    {
        $this->resetForm();
        $this->showPolicyBuilderForm = false;
        $this->showAllPolicyTemplates = true;
        $this->mode = 'form';
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
        $this->loadPolicyRules($policy);
        $this->showPolicyBuilderForm = true;
        $this->showAllPolicyTemplates = false;
        $this->selectedPolicyTemplateKey = 'saved-policy';
        $this->mode = 'form';
    }

    public function duplicatePolicyGroup(int $policyGroupId): void
    {
        $this->editPolicyGroup($policyGroupId);
        $source = $this->policyGroup($policyGroupId);
        $this->editingPolicyGroupId = null;
        $this->policyCode = $this->uniquePolicyCode($source->code.'_COPY');
        $this->policyName = $source->name.' Copy';
        $this->policyStatus = AttendancePolicyGroup::STATUS_INACTIVE;
    }

    public function cancelPolicyEdit(): void
    {
        $this->resetForm();
        $this->showPolicyBuilderForm = false;
        $this->showAllPolicyTemplates = true;
        $this->mode = 'list';
    }

    // === Builder form actions ===

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

        $this->editingPolicyGroupId === null
            ? AttendancePolicyGroup::query()->create($attributes)
            : tap($this->policyGroup($this->editingPolicyGroupId))->update($attributes);

        $this->resetForm();
        $this->showPolicyBuilderForm = false;
        $this->showAllPolicyTemplates = true;
        $this->mode = 'list';
        session()->flash('success', __('Policy group saved.'));
    }

    public function usePolicyTemplate(string $templateKey): void
    {
        if ($this->selectedPolicyTemplateKey === $templateKey && ! $this->showAllPolicyTemplates) {
            $this->resetForm();
            $this->showPolicyBuilderForm = false;
            $this->showAllPolicyTemplates = true;

            return;
        }

        $template = collect($this->policyTemplates())->firstWhere('key', $templateKey);
        if (! is_array($template)) {
            return;
        }

        $this->resetForm();
        $this->applyTemplate($template);
        $this->showPolicyBuilderForm = true;
        $this->showAllPolicyTemplates = false;
        $this->selectedPolicyTemplateKey = $templateKey;
        $this->mode = 'form';
    }

    public function importPolicyTemplate(): void
    {
        if (! $this->ensureSchemaReady()) {
            return;
        }

        $this->authorizeAttendance('people.attendance.manage');

        $this->validate([
            'policyTemplateUpload' => ['required', 'file', 'max:256', 'extensions:json'],
        ]);

        $payload = json_decode($this->templateUploadContents($this->policyTemplateUpload), true);
        if (! is_array($payload)) {
            $this->addError('policyTemplateUpload', __('Upload a valid JSON policy template.'));

            return;
        }

        $template = array_is_list($payload) ? ($payload[0] ?? null) : $payload;
        if (! is_array($template)) {
            $this->addError('policyTemplateUpload', __('The JSON must be a template object or an array of template objects.'));

            return;
        }

        $this->resetForm();
        $this->applyTemplate($template);
        $this->showPolicyBuilderForm = true;
        $this->showPolicyTemplateImportModal = false;
        $this->showAllPolicyTemplates = false;
        $this->selectedPolicyTemplateKey = 'imported-template';
        $this->policyTemplateUpload = null;
        $this->mode = 'form';

        session()->flash('success', __('Policy template loaded. Review and save it as a policy group.'));
    }

    public function render(): View
    {
        $companyId = $this->companyId();
        $schemaReady = $this->schemaReady();

        return view('livewire.people.attendance.policy-studio.library', [
            'schemaReady' => $schemaReady,
            'canManage' => $this->canAttendance('people.attendance.manage'),
            'policyGroups' => $schemaReady
                ? AttendancePolicyGroup::query()
                    ->where('company_id', $companyId)
                    ->with('allowanceRules')
                    ->orderBy('code')
                    ->get()
                : collect(),
            'policyTemplates' => $this->policyTemplates(),
            'payrollPayItems' => $this->payrollPayItems($companyId),
            'shiftTemplates' => $schemaReady
                ? AttendanceShiftTemplate::query()
                    ->where('company_id', $companyId)
                    ->with('punchWindows')
                    ->orderBy('code')
                    ->get()
                : collect(),
        ]);
    }

    private function policyGroup(int $policyGroupId): AttendancePolicyGroup
    {
        return AttendancePolicyGroup::query()
            ->where('company_id', $this->companyId())
            ->findOrFail($policyGroupId);
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

    private function loadPolicyRules(AttendancePolicyGroup $policy): void
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

    private function resetForm(): void
    {
        $this->editingPolicyGroupId = null;
        $this->policyCode = '';
        $this->policyName = '';
        $this->policyEffectiveFrom = now()->toDateString();
        $this->policyEffectiveTo = '';
        $this->policyStatus = AttendancePolicyGroup::STATUS_ACTIVE;
        $this->policyCurrency = 'MYR';
        $this->selectedPolicyTemplateKey = '';
        $this->loadPolicyRules(new AttendancePolicyGroup);
    }

    /** @return list<array<string, mixed>> */
    private function policyTemplates(): array
    {
        return [
            [
                'schema' => PolicyTemplateSerializer::SCHEMA,
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
                'schema' => PolicyTemplateSerializer::SCHEMA,
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
                'schema' => PolicyTemplateSerializer::SCHEMA,
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
    private function applyTemplate(array $template): void
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
}
