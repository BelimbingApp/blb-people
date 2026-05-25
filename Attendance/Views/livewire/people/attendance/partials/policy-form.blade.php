{{-- Context header showing where the user is + a way back to the list. --}}
<div class="flex items-center justify-between gap-3">
    <button type="button" wire:click="cancelPolicyEdit" class="inline-flex items-center gap-1 text-sm font-medium text-muted transition hover:text-accent">
        <x-icon name="heroicon-o-arrow-left" class="h-4 w-4" />
        {{ __('Back to policies') }}
    </button>
    <p class="text-sm font-medium text-ink">
        {{ $editingPolicyGroupId === null ? __('New policy') : __('Editing :code', ['code' => $policyCode ?: '—']) }}
    </p>
</div>

{{-- Templates are a creation affordance only — hide once a saved policy is loaded for edit or duplicate. --}}
@if ($selectedPolicyTemplateKey !== 'saved-policy')
    <x-ui.template-picker
        :templates="$policyTemplates"
        :selected-key="$selectedPolicyTemplateKey"
        :show-all="$showAllPolicyTemplates"
        select-action="usePolicyTemplate"
        upload-action="$set('showPolicyTemplateImportModal', true)"
    />
@endif

@if ($showPolicyBuilderForm)
    <form wire:submit="savePolicyGroup" class="space-y-4">
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
                <p class="mt-1 text-sm text-muted">{{ __('How this policy appears in rosters, imports and audit logs.') }}</p>
            </div>
            <div class="mt-4 grid gap-4 md:grid-cols-2">
                <x-ui.input id="attendance-policy-code" wire:model="policyCode" label="{{ __('Policy code') }}" placeholder="{{ __('STD_8_5') }}" required help="{{ __('Short reference used in rosters and imports.') }}" :error="$errors->first('policyCode')" />
                <x-ui.input id="attendance-policy-name" wire:model="policyName" label="{{ __('Policy name') }}" placeholder="{{ __('Standard 8 to 5') }}" required help="{{ __('Human-readable name for this attendance rulebook.') }}" :error="$errors->first('policyName')" />
            </div>
        </x-ui.card>

        <x-ui.card>
            <div>
                <h2 class="text-base font-semibold text-ink">{{ __('Work time') }}</h2>
                <p class="mt-1 text-sm text-muted">{{ __('How raw clock-in/out becomes payable minutes.') }}</p>
            </div>
            <div class="mt-4 space-y-4">
                <x-ui.alert variant="info">
                    {{ __('Shift start, shift end and break windows are defined in Shift Builder. This policy decides how those scheduled times become payable time, lateness and overtime.') }}
                    <a href="{{ route('people.attendance.shifts') }}" target="_blank" rel="noopener noreferrer" class="font-medium text-accent hover:underline">{{ __('Open Shifts in a new tab') }}</a>
                </x-ui.alert>
                @if ($shiftTemplates->isNotEmpty())
                    @php($sampleShift = $shiftTemplates->first())
                    <div class="rounded-2xl border border-border-default bg-surface-subtle/60 p-card-inner">
                        <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                            <div>
                                <p class="text-[11px] font-semibold uppercase tracking-wider text-muted">{{ __('Shift context') }}</p>
                                <p class="mt-1 text-sm font-medium text-ink">{{ $sampleShift->code }} · {{ $sampleShift->name }}</p>
                                <p class="mt-0.5 font-mono text-xs text-muted">{{ $sampleShift->starts_at }} → {{ $sampleShift->ends_at }} · {{ trans_choice(':count punch window|:count punch windows', $sampleShift->punchWindows->count(), ['count' => $sampleShift->punchWindows->count()]) }}</p>
                            </div>
                            <x-ui.button as="a" variant="secondary" href="{{ route('people.attendance.shifts') }}" target="_blank" rel="noopener noreferrer">
                                {{ __('Tune shifts') }}
                            </x-ui.button>
                        </div>
                    </div>
                @endif
                <div class="grid gap-4 sm:grid-cols-2">
                    <x-ui.select id="attendance-policy-work-rounding-method" wire:model="policyWorkRoundingMethod" label="{{ __('Daily rounding') }}" required help="{{ __('How BLB adjusts raw worked minutes before payable time is calculated.') }}" :error="$errors->first('policyWorkRoundingMethod')">
                        <option value="none">{{ __('None') }}</option>
                        <option value="floor">{{ __('Floor') }}</option>
                        <option value="ceiling">{{ __('Ceiling') }}</option>
                        <option value="nearest">{{ __('Nearest') }}</option>
                    </x-ui.select>
                    <x-ui.input id="attendance-policy-work-rounding-minutes" type="number" min="1" max="60" wire:model="policyWorkRoundingMinutes" label="{{ __('Rounding block') }}" suffix="{{ __('min') }}" help="{{ __('The rounding block, such as 5, 10, or 15 minutes.') }}" :error="$errors->first('policyWorkRoundingMinutes')" />
                </div>
                <x-ui.checkbox id="attendance-policy-exclude-break" wire:model="policyExcludeBreakFromWork" label="{{ __('Exclude break time from work hours') }}" />
                <x-ui.checkbox id="attendance-policy-less-break-lateness" wire:model="policyLessBreakLateness" label="{{ __('Offset lateness by approved break handling') }}" />
            </div>
        </x-ui.card>

        <x-ui.card>
            <div>
                <h2 class="text-base font-semibold text-ink">{{ __('Lateness') }}</h2>
                <p class="mt-1 text-sm text-muted">{{ __('Grace minutes and how late arrivals affect payable time.') }}</p>
            </div>
            <div class="mt-4 space-y-4">
                <div class="grid gap-4 sm:grid-cols-2">
                    <x-ui.select id="attendance-policy-lateness-rounding-method" wire:model="policyLatenessRoundingMethod" label="{{ __('Daily rounding') }}" required help="{{ __('How late minutes are rounded before a deduction is considered.') }}" :error="$errors->first('policyLatenessRoundingMethod')">
                        <option value="none">{{ __('None') }}</option>
                        <option value="floor">{{ __('Floor') }}</option>
                        <option value="ceiling">{{ __('Ceiling') }}</option>
                        <option value="nearest">{{ __('Nearest') }}</option>
                    </x-ui.select>
                    <x-ui.input id="attendance-policy-lateness-rounding-minutes" type="number" min="1" max="60" wire:model="policyLatenessRoundingMinutes" label="{{ __('Rounding block') }}" suffix="{{ __('min') }}" help="{{ __('The lateness rounding block, such as 5 minutes.') }}" :error="$errors->first('policyLatenessRoundingMinutes')" />
                </div>
                <div class="grid gap-4 sm:grid-cols-4">
                    <x-ui.input id="attendance-policy-grace-in" type="number" min="0" max="240" wire:model="policyGraceIn" label="{{ __('In grace') }}" suffix="{{ __('min') }}" help="{{ __('Minutes after shift start before clock-in is treated as late.') }}" :error="$errors->first('policyGraceIn')" />
                    <x-ui.input id="attendance-policy-grace-out" type="number" min="0" max="240" wire:model="policyGraceOut" label="{{ __('Out grace') }}" suffix="{{ __('min') }}" help="{{ __('Minutes before shift end that are tolerated before early-out rules apply.') }}" :error="$errors->first('policyGraceOut')" />
                    <x-ui.input id="attendance-policy-grace-start-break" type="number" min="0" max="240" wire:model="policyGraceStartBreak" label="{{ __('Break out') }}" suffix="{{ __('min') }}" help="{{ __('Tolerance when employees start break later or earlier than scheduled.') }}" :error="$errors->first('policyGraceStartBreak')" />
                    <x-ui.input id="attendance-policy-grace-end-break" type="number" min="0" max="240" wire:model="policyGraceEndBreak" label="{{ __('Break in') }}" suffix="{{ __('min') }}" help="{{ __('Tolerance when employees return from break after the expected time.') }}" :error="$errors->first('policyGraceEndBreak')" />
                </div>
                <div class="grid gap-4 sm:grid-cols-2">
                    @include('people-attendance::livewire.people.attendance.partials.pay-item-field', [
                        'field' => 'policyLatenessPayItem',
                        'id' => 'attendance-policy-lateness-pay-item',
                        'label' => __('Deduction pay item'),
                        'help' => __('Payroll pay items are the source of truth for attendance payroll codes.'),
                        'required' => true,
                    ])
                    <x-ui.input id="attendance-policy-lateness-monthly-minutes" type="number" min="1" max="60" wire:model="policyLatenessMonthlyRoundingMinutes" label="{{ __('Monthly rounding') }}" suffix="{{ __('min') }}" help="{{ __('If payroll deducts lateness monthly, this rounds the monthly total.') }}" :error="$errors->first('policyLatenessMonthlyRoundingMinutes')" />
                </div>
            </div>
        </x-ui.card>

        <x-ui.card>
            <div>
                <h2 class="text-base font-semibold text-ink">{{ __('Overtime & payroll') }}</h2>
                <p class="mt-1 text-sm text-muted">{{ __('When extra minutes become overtime candidates, and which payroll items receive them.') }}</p>
            </div>
            <div class="mt-4 space-y-4">
                <div class="grid gap-4 sm:grid-cols-2">
                    <x-ui.input id="attendance-policy-early-ot-min" type="number" min="0" max="720" wire:model="policyEarlyOvertimeMinimumMinutes" label="{{ __('Before shift') }}" suffix="{{ __('min') }}" help="{{ __('Minimum minutes before shift start before early work becomes an overtime candidate.') }}" :error="$errors->first('policyEarlyOvertimeMinimumMinutes')" />
                    <x-ui.input id="attendance-policy-late-ot-min" type="number" min="0" max="720" wire:model="policyLateOvertimeMinimumMinutes" label="{{ __('After shift') }}" suffix="{{ __('min') }}" help="{{ __('Minimum minutes after shift end before extra work becomes an overtime candidate.') }}" :error="$errors->first('policyLateOvertimeMinimumMinutes')" />
                </div>
                <div class="grid gap-4 sm:grid-cols-2">
                    @foreach ([
                        ['field' => 'policyNormalOvertimePayItem', 'id' => 'attendance-policy-normal-ot-pay-item', 'label' => __('Normal OT item'), 'help' => __('Payroll item for ordinary overtime candidates.'), 'required' => true],
                        ['field' => 'policyExtendedOvertimePayItem', 'id' => 'attendance-policy-extended-ot-pay-item', 'label' => __('Extended OT item'), 'help' => __('Optional payroll item for a later overtime band.'), 'required' => false],
                        ['field' => 'policyRestDayOvertimePayItem', 'id' => 'attendance-policy-rest-day-ot-pay-item', 'label' => __('Rest day OT item'), 'help' => __('Optional payroll item when overtime happens on a roster rest day.'), 'required' => false],
                        ['field' => 'policyHolidayOvertimePayItem', 'id' => 'attendance-policy-holiday-ot-pay-item', 'label' => __('Holiday OT item'), 'help' => __('Optional payroll item when overtime happens on a public holiday.'), 'required' => false],
                    ] as $payItemConfig)
                        @include('people-attendance::livewire.people.attendance.partials.pay-item-field', $payItemConfig)
                    @endforeach
                </div>
                <x-ui.input id="attendance-policy-currency" wire:model="policyCurrency" label="{{ __('Payroll currency') }}" required help="{{ __('Three-letter payroll currency code, for example MYR.') }}" :error="$errors->first('policyCurrency')" />
            </div>
        </x-ui.card>

        <x-ui.card>
            <div>
                <h2 class="text-base font-semibold text-ink">{{ __('Effective dates & activation') }}</h2>
                <p class="mt-1 text-sm text-muted">{{ __('When supervisors can pick this policy, and whether it is currently in use.') }}</p>
            </div>
            <div class="mt-4 grid gap-4 md:grid-cols-3">
                <x-ui.input id="attendance-policy-effective-from" type="date" wire:model="policyEffectiveFrom" label="{{ __('Effective from') }}" required help="{{ __('First date this policy can be assigned to rosters.') }}" :error="$errors->first('policyEffectiveFrom')" />
                <x-ui.input id="attendance-policy-effective-to" type="date" wire:model="policyEffectiveTo" label="{{ __('Effective to') }}" help="{{ __('Optional last date this policy can be assigned.') }}" :error="$errors->first('policyEffectiveTo')" />
                <x-ui.select id="attendance-policy-status" wire:model="policyStatus" label="{{ __('Status') }}" required help="{{ __('Active policies can be used in rosters.') }}" :error="$errors->first('policyStatus')">
                    <option value="active">{{ __('Active') }}</option>
                    <option value="inactive">{{ __('Inactive') }}</option>
                </x-ui.select>
            </div>
        </x-ui.card>

        <div class="flex flex-wrap justify-end gap-2">
            <x-ui.button type="button" variant="secondary" wire:click="cancelPolicyEdit">{{ __('Cancel') }}</x-ui.button>
            <x-ui.button as="a" variant="secondary" href="{{ route('people.attendance.policy-groups.validator') }}">
                {{ __('Open Validator') }}
            </x-ui.button>
            <x-ui.button type="submit" variant="primary" :disabled="! $canManage">
                {{ $editingPolicyGroupId === null ? __('Create policy') : __('Save policy') }}
            </x-ui.button>
        </div>
    </form>
@endif

<x-ui.modal wire:model="showPolicyTemplateImportModal" class="max-w-2xl">
    <div class="p-6 space-y-4">
        <div>
            <h2 class="text-lg font-semibold text-ink">{{ __('Upload Template') }}</h2>
            <p class="mt-1 text-sm text-muted">{{ __('Choose a JSON file containing one policy template object, or an array of template objects.') }}</p>
        </div>
        <div>
            <label for="attendance-policy-template-upload" class="text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Template JSON file') }}</label>
            <input id="attendance-policy-template-upload" type="file" accept="application/json,.json" wire:model="policyTemplateUpload" class="mt-1 block w-full text-sm text-ink file:mr-4 file:rounded file:border-0 file:bg-surface-subtle file:px-3 file:py-1.5 file:text-sm file:font-medium file:text-ink hover:file:bg-surface-subtle/80" />
            @error('policyTemplateUpload') <p class="mt-1 text-sm text-status-danger">{{ $message }}</p> @enderror
        </div>
        <div class="flex justify-end gap-2">
            <x-ui.button type="button" variant="secondary" wire:click="$set('showPolicyTemplateImportModal', false)">{{ __('Cancel') }}</x-ui.button>
            <x-ui.button type="button" variant="primary" wire:click="importPolicyTemplate">{{ __('Upload into builder') }}</x-ui.button>
        </div>
    </div>
</x-ui.modal>
