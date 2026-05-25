<x-ui.card>
    <div class="flex flex-col gap-3 lg:flex-row lg:items-start lg:justify-between">
        <div>
            <h2 class="text-base font-semibold text-ink">{{ __('Policy Validator') }}</h2>
            <p class="mt-1 text-sm text-muted">{{ __('Validate a policy group, then simulate real clock times before the policy is used in rosters.') }}</p>
        </div>
        <x-ui.badge variant="info">{{ __('Preview only') }}</x-ui.badge>
    </div>

    <div class="mt-4 grid gap-4 lg:grid-cols-2">
        <div class="space-y-4 rounded-2xl border border-border-default p-card-inner">
            <div class="grid gap-4 md:grid-cols-2">
                <x-ui.select id="attendance-policy-preview-policy" wire:model="policyPreviewPolicyId" label="{{ __('Policy group') }}" :error="$errors->first('policyPreviewPolicyId')">
                    <option value="">{{ __('Choose policy') }}</option>
                    @foreach ($policyGroups as $group)
                        <option value="{{ $group->id }}">{{ $group->code }} - {{ $group->name }}</option>
                    @endforeach
                </x-ui.select>
                <x-ui.select id="attendance-policy-preview-shift" wire:model="policyPreviewShiftId" label="{{ __('Shift template') }}" :error="$errors->first('policyPreviewShiftId')">
                    <option value="">{{ __('Choose shift') }}</option>
                    @foreach ($shiftTemplates as $shift)
                        <option value="{{ $shift->id }}">{{ $shift->code }} - {{ $shift->name }}</option>
                    @endforeach
                </x-ui.select>
            </div>

            <div class="grid gap-4 md:grid-cols-3">
                <x-ui.input id="attendance-policy-preview-date" type="date" wire:model="policyPreviewDate" label="{{ __('Date') }}" :error="$errors->first('policyPreviewDate')" />
                <x-ui.input id="attendance-policy-preview-in" type="time" wire:model="policyPreviewClockIn" label="{{ __('Clock in') }}" :error="$errors->first('policyPreviewClockIn')" />
                <x-ui.input id="attendance-policy-preview-out" type="time" wire:model="policyPreviewClockOut" label="{{ __('Clock out') }}" :error="$errors->first('policyPreviewClockOut')" />
            </div>

            <div class="flex flex-wrap gap-2">
                <x-ui.button type="button" variant="secondary" wire:click="validatePolicyPreview">
                    <x-icon name="heroicon-o-shield-check" class="h-4 w-4" />
                    {{ __('Validate policy') }}
                </x-ui.button>
                <x-ui.button type="button" variant="primary" wire:click="simulatePolicyPreview">
                    <x-icon name="heroicon-o-play-circle" class="h-4 w-4" />
                    {{ __('Simulate day') }}
                </x-ui.button>
            </div>
        </div>

        <div class="space-y-3 rounded-2xl border border-border-default p-card-inner">
            <div class="text-xs font-semibold uppercase tracking-wide text-muted">{{ __('What this protects') }}</div>
            <ul class="space-y-2 text-sm text-muted">
                <li class="flex gap-2"><x-icon name="heroicon-o-check-circle" class="mt-0.5 h-4 w-4 text-accent" /> <span>{{ __('Policy findings use stable codes, so imports and operators can rely on them.') }}</span></li>
                <li class="flex gap-2"><x-icon name="heroicon-o-check-circle" class="mt-0.5 h-4 w-4 text-accent" /> <span>{{ __('Simulation does not create attendance records or payroll inputs.') }}</span></li>
                <li class="flex gap-2"><x-icon name="heroicon-o-check-circle" class="mt-0.5 h-4 w-4 text-accent" /> <span>{{ __('Overtime remains a candidate until approved by workflow.') }}</span></li>
            </ul>
        </div>
    </div>
</x-ui.card>

<div class="grid gap-4 xl:grid-cols-2">
    <x-ui.card>
        <div class="flex items-center justify-between gap-3">
            <h3 class="text-base font-semibold text-ink">{{ __('Validation findings') }}</h3>
            @if ($this->policyValidationResult)
                <x-ui.badge :variant="$this->policyValidationResult['status'] === 'error' ? 'danger' : ($this->policyValidationResult['status'] === 'warning' ? 'warning' : 'success')">{{ __(ucfirst($this->policyValidationResult['status'])) }}</x-ui.badge>
            @endif
        </div>
        <div class="mt-4 space-y-3">
            @if (! $this->policyValidationResult)
                <p class="text-sm text-muted">{{ __('Choose a policy group and run validation to see setup issues before activation.') }}</p>
            @elseif (empty($this->policyValidationResult['findings']))
                <x-ui.alert variant="success">{{ __('No validation findings for this policy group.') }}</x-ui.alert>
            @else
                @foreach ($this->policyValidationResult['findings'] as $finding)
                    <div class="rounded-2xl border border-border-default p-3" wire:key="policy-finding-{{ $loop->index }}">
                        <div class="flex items-start justify-between gap-3">
                            <div class="font-mono text-xs text-muted">{{ $finding['code'] }}</div>
                            <x-ui.badge :variant="$finding['severity'] === 'error' ? 'danger' : ($finding['severity'] === 'warning' ? 'warning' : 'info')">{{ __(ucfirst($finding['severity'])) }}</x-ui.badge>
                        </div>
                        <p class="mt-2 text-sm text-ink">{{ $finding['message'] }}</p>
                        <p class="mt-1 font-mono text-xs text-muted">{{ $finding['path'] }}</p>
                    </div>
                @endforeach
            @endif
        </div>
    </x-ui.card>

    <x-ui.card>
        <div class="flex items-center justify-between gap-3">
            <h3 class="text-base font-semibold text-ink">{{ __('Simulation preview') }}</h3>
            @if ($this->policySimulationResult)
                <x-ui.badge :variant="$this->policySimulationResult['status'] === 'ok' ? 'success' : 'warning'">{{ __(ucfirst($this->policySimulationResult['status'])) }}</x-ui.badge>
            @endif
        </div>
        @if (! $this->policySimulationResult)
            <p class="mt-4 text-sm text-muted">{{ __('Run a simulation to preview lateness, payable minutes, overtime candidates, and allowance candidates.') }}</p>
        @elseif (($this->policySimulationResult['status'] ?? null) === 'error')
            <div class="mt-4 space-y-3">
                @foreach ($this->policySimulationResult['findings'] as $finding)
                    <x-ui.alert variant="danger" wire:key="simulation-error-{{ $loop->index }}">{{ $finding['message'] }}</x-ui.alert>
                @endforeach
            </div>
        @else
            <div class="mt-4 grid gap-3 sm:grid-cols-2">
                @foreach ([
                    __('Worked') => $this->policySimulationResult['metrics']['worked_minutes'],
                    __('Payable') => $this->policySimulationResult['metrics']['payable_minutes'],
                    __('Late') => $this->policySimulationResult['metrics']['late_minutes'],
                    __('OT candidate') => $this->policySimulationResult['metrics']['overtime_candidate_minutes'],
                ] as $label => $minutes)
                    <div class="rounded-2xl border border-border-default p-3">
                        <div class="text-xs font-semibold uppercase tracking-wide text-muted">{{ $label }}</div>
                        <div class="mt-1 text-2xl font-semibold tabular-nums text-ink">{{ number_format($minutes / 60, 2) }}h</div>
                        <div class="text-xs text-muted">{{ trans_choice(':count minute|:count minutes', $minutes, ['count' => $minutes]) }}</div>
                    </div>
                @endforeach
            </div>
            <p class="mt-4 text-sm text-muted">{{ $this->policySimulationResult['explanation'] }}</p>
            <div class="mt-4">
                <div class="text-xs font-semibold uppercase tracking-wide text-muted">{{ __('Allowance candidates') }}</div>
                <div class="mt-2 space-y-2">
                    @forelse ($this->policySimulationResult['allowance_candidates'] as $candidate)
                        <div class="rounded-2xl border border-border-default p-3" wire:key="allowance-candidate-{{ $candidate['code'] }}">
                            <div class="font-medium text-ink">{{ $candidate['code'] }} - {{ $candidate['name'] }}</div>
                            <div class="mt-1 text-xs text-muted">{{ __('Matched rows: :count', ['count' => count($candidate['matched_rows'])]) }}</div>
                        </div>
                    @empty
                        <p class="text-sm text-muted">{{ __('No daily allowance candidates matched this simulation.') }}</p>
                    @endforelse
                </div>
            </div>
        @endif
    </x-ui.card>
</div>
