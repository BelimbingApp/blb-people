<?php

namespace App\Modules\People\Attendance\Livewire\PolicyStudio;

use App\Modules\People\Attendance\Livewire\Concerns\InteractsWithAttendanceScreen;
use App\Modules\People\Attendance\Models\AttendanceAllowanceRule;
use App\Modules\People\Attendance\Models\AttendancePolicyGroup;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\Rule;
use Livewire\Component;

class Allowances extends Component
{
    use InteractsWithAttendanceScreen;

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

    public function mount(): void
    {
        $this->allowanceEffectiveFrom = now()->toDateString();
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
        $this->allowanceConditionPreset = $this->presetFromPredicate($predicate);
        $this->allowanceMinWorkedMinutes = (string) ($predicate['min_worked_minutes'] ?? '480');
        $this->allowanceClockOutAfter = (string) ($predicate['clock_out_after'] ?? '');
        $this->allowanceClockOutBefore = (string) ($predicate['clock_out_before'] ?? '');
        $this->allowanceEffectiveFrom = $rule->effective_from?->toDateString() ?? now()->toDateString();
        $this->allowanceStatus = $rule->status;
    }

    public function cancelAllowanceEdit(): void
    {
        $this->resetForm();
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

    public function render(): View
    {
        $companyId = $this->companyId();
        $schemaReady = $this->schemaReady();

        return view('livewire.people.attendance.policy-studio.allowances', [
            'schemaReady' => $schemaReady,
            'canManage' => $this->canAttendance('people.attendance.manage'),
            'policyGroups' => $schemaReady
                ? AttendancePolicyGroup::query()
                    ->where('company_id', $companyId)
                    ->orderBy('code')
                    ->get()
                : collect(),
            'allowanceRules' => $schemaReady
                ? AttendanceAllowanceRule::query()
                    ->where('company_id', $companyId)
                    ->with('policyGroup')
                    ->orderBy('code')
                    ->get()
                : collect(),
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
}
