<?php

namespace App\Modules\People\Attendance\Livewire;

use App\Modules\People\Attendance\Livewire\Concerns\InteractsWithAttendanceScreen;
use App\Modules\People\Attendance\Models\AttendanceAllowanceRule;
use App\Modules\People\Attendance\Models\AttendancePolicyGroup;
use App\Modules\People\Attendance\Models\AttendanceShiftTemplate;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Allowance rule list + builder.
 *
 * The list is the default workspace. New rules start from lightweight built-in
 * templates; saved rules and duplicates open directly in the form without the
 * template picker because the reusable definition already exists.
 */
class AllowanceRules extends Component
{
    use InteractsWithAttendanceScreen;

    #[Url(as: 'mode')]
    public string $mode = 'list';

    public bool $showAllowanceBuilderForm = false;

    public bool $showAllAllowanceTemplates = true;

    #[Url(as: 'template')]
    public string $selectedAllowanceTemplateKey = '';

    public string $allowancePolicyGroupId = '';

    public string $allowanceShiftTemplateId = '';

    public string $allowanceCode = '';

    public string $allowanceName = '';

    public string $allowanceType = AttendanceAllowanceRule::TYPE_DAILY;

    public string $allowanceAmount = '0.00';

    public string $allowanceResolutionMethod = AttendanceAllowanceRule::RESOLUTION_SUM;

    public string $allowanceConditionPreset = 'always';

    public string $allowanceMinWorkedMinutes = '480';

    public string $allowanceClockOutAfter = '';

    public string $allowanceClockOutBefore = '';

    public string $allowanceEffectiveFrom = '';

    public string $allowanceStatus = 'active';

    #[Url(as: 'allowance')]
    public ?int $editingAllowanceRuleId = null;

    public function mount(): void
    {
        $selectedAllowanceTemplateKey = $this->selectedAllowanceTemplateKey;
        $this->allowanceEffectiveFrom = now()->toDateString();

        if ($this->editingAllowanceRuleId !== null) {
            $this->editAllowanceRule($this->editingAllowanceRuleId);

            return;
        }

        if ($this->mode === 'form') {
            $this->startNewAllowanceRule();

            if ($selectedAllowanceTemplateKey !== '') {
                $this->useAllowanceTemplate($selectedAllowanceTemplateKey);
            }
        }
    }

    public function startNewAllowanceRule(): void
    {
        $this->resetForm();
        $this->showAllowanceBuilderForm = false;
        $this->showAllAllowanceTemplates = true;
        $this->mode = 'form';
    }

    public function useAllowanceTemplate(string $templateKey): void
    {
        if ($this->selectedAllowanceTemplateKey === $templateKey && ! $this->showAllAllowanceTemplates) {
            $this->resetForm();
            $this->showAllowanceBuilderForm = false;
            $this->showAllAllowanceTemplates = true;

            return;
        }

        $template = collect($this->allowanceTemplates())->firstWhere('key', $templateKey);
        if (! is_array($template)) {
            return;
        }

        $this->resetForm();
        $this->applyAllowanceTemplate($template);
        $this->showAllowanceBuilderForm = true;
        $this->showAllAllowanceTemplates = false;
        $this->selectedAllowanceTemplateKey = $templateKey;
        $this->mode = 'form';
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
            'allowanceShiftTemplateId' => ['nullable', 'integer'],
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

        $shiftTemplateId = $this->blankToNull($validated['allowanceShiftTemplateId'] ?? null);
        if ($shiftTemplateId !== null) {
            $shiftTemplateId = AttendanceShiftTemplate::query()
                ->where('company_id', $companyId)
                ->findOrFail((int) $shiftTemplateId)
                ->id;
        }

        $attributes = [
            'company_id' => $companyId,
            'attendance_policy_group_id' => $policyGroupId,
            'attendance_shift_template_id' => $shiftTemplateId,
            'code' => str($validated['allowanceCode'])->upper()->toString(),
            'name' => $validated['allowanceName'],
            'allowance_type' => $validated['allowanceType'],
            'ceiling_amount' => null,
            'resolution_method' => $validated['allowanceResolutionMethod'],
            'condition_rows' => [$this->conditionRow($validated)],
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

        $this->resetForm();
        $this->showAllowanceBuilderForm = false;
        $this->showAllAllowanceTemplates = true;
        $this->mode = 'list';
        session()->flash('success', __('Allowance rule saved. Validate the linked policy group before using it for payroll handoff.'));
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
        $this->allowanceShiftTemplateId = $rule->attendance_shift_template_id === null ? '' : (string) $rule->attendance_shift_template_id;
        $this->allowanceCode = $rule->code;
        $this->allowanceName = $rule->name;
        $this->allowanceType = $rule->allowance_type;
        $this->allowanceAmount = (string) ($row['amount'] ?? '0.00');
        $this->allowanceResolutionMethod = $rule->resolution_method;
        $this->allowanceConditionPreset = $this->presetFromPredicate($predicate);
        $this->allowanceMinWorkedMinutes = (string) ($predicate['min_worked_minutes'] ?? '480');
        $this->allowanceClockOutAfter = (string) ($predicate['clock_out_after'] ?? '');
        $this->allowanceClockOutBefore = (string) ($predicate['clock_out_before'] ?? '');
        $this->allowanceEffectiveFrom = $rule->effective_from?->toDateString() ?? now()->toDateString();
        $this->allowanceStatus = $rule->status;
        $this->showAllowanceBuilderForm = true;
        $this->showAllAllowanceTemplates = false;
        $this->selectedAllowanceTemplateKey = 'saved-allowance';
        $this->mode = 'form';
    }

    public function duplicateAllowanceRule(int $ruleId): void
    {
        $this->editAllowanceRule($ruleId);
        $source = $this->allowanceRule($ruleId);
        $this->editingAllowanceRuleId = null;
        $this->allowanceCode = $this->uniqueAllowanceCode($source->code.'_COPY');
        $this->allowanceName = $source->name.' Copy';
        $this->allowanceStatus = 'inactive';
    }

    public function cancelAllowanceEdit(): void
    {
        $this->resetForm();
        $this->showAllowanceBuilderForm = false;
        $this->showAllAllowanceTemplates = true;
        $this->mode = 'list';
    }

    public function deleteAllowanceRule(int $ruleId): void
    {
        if (! $this->ensureSchemaReady()) {
            return;
        }

        $this->authorizeAttendance('people.attendance.manage');

        $this->allowanceRule($ruleId)->delete();

        if ($this->editingAllowanceRuleId === $ruleId) {
            $this->resetForm();
        }

        session()->flash('success', __('Allowance rule deleted.'));
    }

    public function toggleAllowanceStatus(int $ruleId): void
    {
        if (! $this->ensureSchemaReady()) {
            return;
        }

        $this->authorizeAttendance('people.attendance.manage');

        $rule = $this->allowanceRule($ruleId);
        $rule->update([
            'status' => $rule->status === 'active' ? 'inactive' : 'active',
        ]);

        session()->flash('success', __('Allowance rule status updated.'));
    }

    public function render(): View
    {
        $companyId = $this->companyId();
        $schemaReady = $this->schemaReady();

        return view('people-attendance::livewire.people.attendance.allowance-rules', [
            'schemaReady' => $schemaReady,
            'canManage' => $this->canAttendance('people.attendance.manage'),
            'policyGroups' => $schemaReady
                ? AttendancePolicyGroup::query()
                    ->where('company_id', $companyId)
                    ->orderBy('code')
                    ->get()
                : collect(),
            'shiftTemplates' => $schemaReady
                ? AttendanceShiftTemplate::query()
                    ->where('company_id', $companyId)
                    ->orderBy('code')
                    ->get()
                : collect(),
            'allowanceRules' => $schemaReady
                ? AttendanceAllowanceRule::query()
                    ->where('company_id', $companyId)
                    ->with(['policyGroup', 'shiftTemplate'])
                    ->orderBy('code')
                    ->get()
                : collect(),
            'allowanceTemplates' => $this->allowanceTemplates(),
        ]);
    }

    private function allowanceRule(int $ruleId): AttendanceAllowanceRule
    {
        return AttendanceAllowanceRule::query()
            ->where('company_id', $this->companyId())
            ->findOrFail($ruleId);
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    private function conditionRow(array $validated): array
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
            'description' => $this->conditionDescription($preset),
            'amount' => (float) $validated['allowanceAmount'],
            'predicate' => $predicate,
        ];
    }

    private function conditionDescription(string $preset): string
    {
        return match ($preset) {
            'min_worked' => 'Pay when worked minutes meet the configured threshold.',
            'clock_out_after' => 'Pay when clock-out is after the configured time.',
            'clock_out_window' => 'Pay when clock-out falls inside the configured time window.',
            'min_worked_and_after' => 'Pay when worked minutes meet the threshold and clock-out is after the configured time.',
            default => 'Pay whenever the linked policy applies.',
        };
    }

    /** @return list<array<string, mixed>> */
    private function allowanceTemplates(): array
    {
        return [
            [
                'key' => 'blank-allowance',
                'code' => 'ALLOWANCE',
                'name' => __('Blank allowance'),
                'summary' => __('Start with a neutral always-pay rule and fill the business meaning yourself.'),
                'best_for' => __('One-off company rules that do not match a common meal, transport or night pattern.'),
                'type' => AttendanceAllowanceRule::TYPE_DAILY,
                'amount' => '1.00',
                'resolution_method' => AttendanceAllowanceRule::RESOLUTION_SUM,
                'condition_preset' => 'always',
            ],
            [
                'key' => 'meal-after-worked-time',
                'code' => 'MEAL_ALLOWANCE',
                'name' => __('Meal allowance'),
                'summary' => __('Daily meal allowance once worked minutes reach a threshold.'),
                'best_for' => __('Meal claims driven by attendance duration rather than manual claim submission.'),
                'type' => AttendanceAllowanceRule::TYPE_DAILY,
                'amount' => '10.00',
                'resolution_method' => AttendanceAllowanceRule::RESOLUTION_SUM,
                'condition_preset' => 'min_worked',
                'min_worked_minutes' => '480',
            ],
            [
                'key' => 'late-out-transport',
                'code' => 'LATE_TRANSPORT',
                'name' => __('Late-out transport'),
                'summary' => __('Daily transport allowance when clock-out is after a configured time.'),
                'best_for' => __('Taxi, ride-hailing or transport top-ups for employees who leave late.'),
                'type' => AttendanceAllowanceRule::TYPE_DAILY,
                'amount' => '20.00',
                'resolution_method' => AttendanceAllowanceRule::RESOLUTION_SUM,
                'condition_preset' => 'clock_out_after',
                'clock_out_after' => '21:00',
            ],
            [
                'key' => 'night-window',
                'code' => 'NIGHT_ALLOWANCE',
                'name' => __('Night allowance'),
                'summary' => __('Daily allowance when clock-out falls inside a night window.'),
                'best_for' => __('Night differential rules before supervisors choose the applicable policy or shift scope.'),
                'type' => AttendanceAllowanceRule::TYPE_DAILY,
                'amount' => '25.00',
                'resolution_method' => AttendanceAllowanceRule::RESOLUTION_SUM,
                'condition_preset' => 'clock_out_window',
                'clock_out_after' => '22:00',
                'clock_out_before' => '06:00',
            ],
            [
                'key' => 'monthly-attendance',
                'code' => 'MONTHLY_ATTENDANCE',
                'name' => __('Monthly attendance allowance'),
                'summary' => __('Monthly attendance allowance that can later be scoped to a policy group.'),
                'best_for' => __('Fixed monthly attendance incentives whose earning logic is kept in Attendance.'),
                'type' => AttendanceAllowanceRule::TYPE_MONTHLY,
                'amount' => '100.00',
                'resolution_method' => AttendanceAllowanceRule::RESOLUTION_MAX,
                'condition_preset' => 'always',
            ],
        ];
    }

    /** @param array<string, mixed> $template */
    private function applyAllowanceTemplate(array $template): void
    {
        $this->allowanceCode = $this->uniqueAllowanceCode((string) ($template['code'] ?? 'ALLOWANCE'));
        $this->allowanceName = (string) ($template['name'] ?? __('Allowance rule'));
        $this->allowanceType = (string) ($template['type'] ?? AttendanceAllowanceRule::TYPE_DAILY);
        $this->allowanceAmount = (string) ($template['amount'] ?? '0.00');
        $this->allowanceResolutionMethod = (string) ($template['resolution_method'] ?? AttendanceAllowanceRule::RESOLUTION_SUM);
        $this->allowanceConditionPreset = (string) ($template['condition_preset'] ?? 'always');
        $this->allowanceMinWorkedMinutes = (string) ($template['min_worked_minutes'] ?? $this->allowanceMinWorkedMinutes);
        $this->allowanceClockOutAfter = (string) ($template['clock_out_after'] ?? '');
        $this->allowanceClockOutBefore = (string) ($template['clock_out_before'] ?? '');
    }

    /**
     * @param  array<string, mixed>  $predicate
     */
    private function presetFromPredicate(array $predicate): string
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

    private function resetForm(): void
    {
        $this->editingAllowanceRuleId = null;
        $this->selectedAllowanceTemplateKey = '';
        $this->allowancePolicyGroupId = '';
        $this->allowanceShiftTemplateId = '';
        $this->allowanceCode = '';
        $this->allowanceName = '';
        $this->allowanceType = AttendanceAllowanceRule::TYPE_DAILY;
        $this->allowanceAmount = '0.00';
        $this->allowanceResolutionMethod = AttendanceAllowanceRule::RESOLUTION_SUM;
        $this->allowanceConditionPreset = 'always';
        $this->allowanceMinWorkedMinutes = '480';
        $this->allowanceClockOutAfter = '';
        $this->allowanceClockOutBefore = '';
        $this->allowanceEffectiveFrom = now()->toDateString();
        $this->allowanceStatus = 'active';
    }

    private function uniqueAllowanceCode(string $baseCode): string
    {
        $baseCode = str($baseCode)->upper()->replaceMatches('/[^A-Z0-9_-]+/', '_')->trim('_')->toString() ?: 'ALLOWANCE';
        $candidate = $baseCode;
        $suffix = 2;

        while (AttendanceAllowanceRule::query()
            ->where('company_id', $this->companyId())
            ->where('code', $candidate)
            ->exists()) {
            $candidate = $baseCode.'_'.$suffix;
            $suffix++;
        }

        return $candidate;
    }
}
