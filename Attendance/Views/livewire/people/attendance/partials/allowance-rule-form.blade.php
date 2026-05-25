<div class="space-y-4">
    <div class="flex items-center justify-between gap-3">
        <button type="button" wire:click="cancelAllowanceEdit" class="inline-flex items-center gap-1 text-sm font-medium text-muted transition hover:text-accent">
            <x-icon name="heroicon-o-arrow-left" class="h-4 w-4" />
            {{ __('Back to allowance rules') }}
        </button>
        <p class="text-sm font-medium text-ink">
            {{ $editingAllowanceRuleId === null ? __('New allowance rule') : __('Editing :code', ['code' => $allowanceCode ?: '-']) }}
        </p>
    </div>

    @if ($selectedAllowanceTemplateKey !== 'saved-allowance')
        <x-ui.template-picker
            :templates="$allowanceTemplates"
            :selected-key="$selectedAllowanceTemplateKey"
            :show-all="$showAllAllowanceTemplates"
            select-action="useAllowanceTemplate"
        />
    @endif

    @if ($showAllowanceBuilderForm)
        <form wire:submit="saveAllowanceRule" class="space-y-4">
            @if ($errors->any())
                <x-ui.alert variant="danger">
                    <p class="font-medium">{{ __('Fix these before saving:') }}</p>
                    <ul class="mt-2 list-disc pl-5 text-sm">
                        @foreach ($errors->all() as $message)
                            <li>{{ $message }}</li>
                        @endforeach
                    </ul>
                </x-ui.alert>
            @endif

            <x-ui.card>
                <div>
                    <h2 class="text-base font-semibold text-ink">{{ __('Identification') }}</h2>
                    <p class="mt-1 text-sm text-muted">{{ __('How this reusable allowance definition appears in validation, simulation and payroll handoff previews.') }}</p>
                </div>
                <div class="mt-4 grid gap-4 md:grid-cols-2">
                    <x-ui.input id="attendance-allowance-code" wire:model="allowanceCode" label="{{ __('Code') }}" placeholder="{{ __('NIGHT_ALLOWANCE') }}" required :error="$errors->first('allowanceCode')" />
                    <x-ui.input id="attendance-allowance-name" wire:model="allowanceName" label="{{ __('Name') }}" placeholder="{{ __('Night allowance') }}" required :error="$errors->first('allowanceName')" />
                </div>
            </x-ui.card>

            <x-ui.card>
                <div>
                    <h2 class="text-base font-semibold text-ink">{{ __('Condition') }}</h2>
                    <p class="mt-1 text-sm text-muted">{{ __('Choose the typed predicate Attendance evaluates before Payroll classifies the pay item.') }}</p>
                </div>
                <div class="mt-4 grid gap-3 md:grid-cols-5">
                @foreach ([
                    'always' => [__('Always'), __('No extra condition.')],
                    'min_worked' => [__('Worked time'), __('Require minimum worked minutes.')],
                    'clock_out_after' => [__('Late out'), __('Require clock-out after a time.')],
                    'clock_out_window' => [__('Time window'), __('Require clock-out inside a window.')],
                    'min_worked_and_after' => [__('Worked + late'), __('Require worked minutes and late clock-out.')],
                ] as $presetKey => [$presetLabel, $presetHelp])
                    <button type="button" wire:click="$set('allowanceConditionPreset', '{{ $presetKey }}')" class="rounded-2xl border p-3 text-left transition hover:-translate-y-0.5 {{ $allowanceConditionPreset === $presetKey ? 'border-accent bg-surface-subtle' : 'border-border-default bg-surface-card' }}">
                        <div class="text-sm font-medium text-ink">{{ $presetLabel }}</div>
                        <div class="mt-1 text-xs text-muted">{{ $presetHelp }}</div>
                    </button>
                @endforeach
                </div>

                <div class="mt-2 text-sm text-muted">
                    {{ match ($allowanceConditionPreset) {
                        'always' => __('Pay with no additional attendance condition.'),
                        'min_worked' => __('Pay when worked minutes meet the configured threshold.'),
                        'clock_out_after' => __('Pay when clock-out is after the configured time.'),
                        'clock_out_window' => __('Pay when clock-out falls inside the configured time window.'),
                        'min_worked_and_after' => __('Pay when worked minutes meet the threshold and clock-out is after the configured time.'),
                        default => '',
                    } }}
                </div>

                <div class="mt-4 space-y-4">
                    <div class="grid gap-4 md:grid-cols-2 lg:grid-cols-3">
                        <x-ui.input id="attendance-allowance-amount" type="number" step="0.01" min="0.01" wire:model="allowanceAmount" label="{{ __('Fixed Amount') }}" required :error="$errors->first('allowanceAmount')" />
                        @if (in_array($allowanceConditionPreset, ['min_worked', 'min_worked_and_after'], true))
                            <x-ui.input id="attendance-allowance-min-worked" type="number" min="0" max="1440" wire:model="allowanceMinWorkedMinutes" label="{{ __('Minimum worked minutes') }}" :error="$errors->first('allowanceMinWorkedMinutes')" />
                        @endif
                        @if (in_array($allowanceConditionPreset, ['clock_out_after', 'clock_out_window', 'min_worked_and_after'], true))
                            <x-ui.input id="attendance-allowance-clock-out-after" type="time" wire:model="allowanceClockOutAfter" label="{{ __('Clock-out after') }}" :error="$errors->first('allowanceClockOutAfter')" />
                        @endif
                        @if ($allowanceConditionPreset === 'clock_out_window')
                            <x-ui.input id="attendance-allowance-clock-out-before" type="time" wire:model="allowanceClockOutBefore" label="{{ __('Clock-out before') }}" :error="$errors->first('allowanceClockOutBefore')" />
                        @endif
                    </div>
                </div>
            </x-ui.card>

            <x-ui.card>
                <div>
                    <h2 class="text-base font-semibold text-ink">{{ __('Payroll & scope') }}</h2>
                    <p class="mt-1 text-sm text-muted">{{ __('Optionally limit this allowance rule to a Policy group or shift. These scope filters apply in addition to the selected condition.') }}</p>
                </div>
                <div class="mt-4 grid gap-4 lg:grid-cols-2">
                    <x-ui.select id="attendance-allowance-policy" wire:model="allowancePolicyGroupId" label="{{ __('Policy group') }}" help="{{ __('Optional. If selected, the rule only applies when that Policy group applies.') }}" :error="$errors->first('allowancePolicyGroupId')">
                        <option value="">{{ __('Available to any policy') }}</option>
                        @foreach ($policyGroups as $group)
                            <option value="{{ $group->id }}">{{ $group->code }} - {{ $group->name }}</option>
                        @endforeach
                    </x-ui.select>
                    <x-ui.select id="attendance-allowance-shift" wire:model="allowanceShiftTemplateId" label="{{ __('Only when this shift is worked') }}" help="{{ __('Leave blank to apply regardless of shift.') }}" :error="$errors->first('allowanceShiftTemplateId')">
                        <option value="">{{ __('Any shift') }}</option>
                        @foreach ($shiftTemplates as $shift)
                            <option value="{{ $shift->id }}">{{ $shift->code }} - {{ $shift->name }}</option>
                        @endforeach
                    </x-ui.select>
                    <x-ui.input id="attendance-allowance-effective-from" type="date" wire:model="allowanceEffectiveFrom" label="{{ __('Effective from') }}" required :error="$errors->first('allowanceEffectiveFrom')" />
                    <x-ui.select id="attendance-allowance-type" wire:model="allowanceType" label="{{ __('Type') }}" :error="$errors->first('allowanceType')">
                        <option value="daily">{{ __('Daily') }}</option>
                        <option value="monthly">{{ __('Monthly') }}</option>
                    </x-ui.select>
                    <x-ui.select id="attendance-allowance-resolution" wire:model="allowanceResolutionMethod" label="{{ __('If more than one row matches') }}" :error="$errors->first('allowanceResolutionMethod')">
                        <option value="sum">{{ __('Sum') }}</option>
                        <option value="min">{{ __('Minimum') }}</option>
                        <option value="max">{{ __('Maximum') }}</option>
                    </x-ui.select>
                    <x-ui.select id="attendance-allowance-status" wire:model="allowanceStatus" label="{{ __('Status') }}" :error="$errors->first('allowanceStatus')">
                        <option value="active">{{ __('Active') }}</option>
                        <option value="inactive">{{ __('Inactive') }}</option>
                    </x-ui.select>
                </div>
                <p class="mt-4 text-xs text-muted">
                    {{ __('Cannot find the policy you need?') }}
                    <a href="{{ route('people.attendance.policy-groups') }}" class="text-accent hover:underline">{{ __('Open Policy Groups') }}</a>
                </p>
            </x-ui.card>

            <div class="flex flex-wrap justify-end gap-2">
                <x-ui.button type="button" variant="secondary" wire:click="cancelAllowanceEdit">{{ __('Cancel') }}</x-ui.button>
                <x-ui.button type="submit" variant="primary" :disabled="! $canManage">
                    <x-icon name="heroicon-o-check-circle" class="h-4 w-4" />
                    {{ $editingAllowanceRuleId === null ? __('Create rule') : __('Save changes') }}
                </x-ui.button>
            </div>
        </form>
    @endif
</div>

