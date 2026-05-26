<div>
    <x-slot name="title">{{ __('Payroll') }}</x-slot>

    <div class="space-y-section-gap">
        <x-ui.page-header :title="__('Payroll')" :subtitle="__('Inspect payroll runs, pay-item classifications, statutory profiles, and country-pack rule tables.')">
            <x-slot name="help">
                {{ __('This is the first payroll workbench over the country-neutral backend. Malaysia statutory values shown here are dev fixtures and country-pack payloads, not production statutory calculator output yet.') }}
            </x-slot>
        </x-ui.page-header>

        @if (session('success'))
            <x-ui.alert variant="success">{{ session('success') }}</x-ui.alert>
        @endif

        @if (session('error'))
            <x-ui.alert variant="danger">{{ session('error') }}</x-ui.alert>
        @endif

        <x-ui.card>
            <div class="mb-4 flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
                <x-ui.tabs
                    :tabs="$tabs"
                    :default="$tab"
                    size="sm"
                    persistence="none"
                    wire-action="setTab"
                    class="w-full lg:w-auto"
                >
                    <x-ui.tab id="runs" />
                    <x-ui.tab id="pay-items" />
                    <x-ui.tab id="profiles" />
                    <x-ui.tab id="rules" />
                </x-ui.tabs>

                @if ($tab === 'runs')
                    <div class="w-full lg:w-80">
                        <x-ui.search-input
                            wire:model.live.debounce.300ms="search"
                            placeholder="{{ __('Search runs by code, name, or status...') }}"
                        />
                    </div>
                @endif
            </div>

            @if ($tab === 'runs')
                <div class="grid gap-6 xl:grid-cols-[minmax(0,1fr)_minmax(28rem,0.9fr)]">
                    <div class="space-y-4">
                        <x-ui.table container="flush" :caption="__('Payroll runs')">

                            <x-slot name="head">
                                    <tr>
                                        <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Run') }}</th>
                                        <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Period') }}</th>
                                        <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Status') }}</th>
                                        <th class="px-table-cell-x py-table-header-y text-right text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Rows') }}</th>
                                        <th class="px-table-cell-x py-table-header-y text-right text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Actions') }}</th>
                                    </tr>
                                </x-slot>

                                    @forelse ($runs as $run)
                                        <tr wire:key="payroll-run-{{ $run->id }}">
                                            <td class="px-table-cell-x py-table-cell-y">
                                                <button type="button" wire:click="selectRun({{ $run->id }})" class="text-left">
                                                    <div class="font-medium text-ink">{{ $run->name }}</div>
                                                    <div class="text-xs text-muted font-mono">{{ $run->code }}</div>
                                                </button>
                                            </td>
                                            <td class="px-table-cell-x py-table-cell-y whitespace-nowrap text-sm text-muted">
                                                <div>{{ $run->period?->name ?? '-' }}</div>
                                                <div class="text-xs tabular-nums">{{ $run->period?->pay_date?->toDateString() ?? '-' }}</div>
                                            </td>
                                            <td class="px-table-cell-x py-table-cell-y whitespace-nowrap">
                                                <x-ui.badge :variant="$this->statusVariant($run->status)">{{ __(ucfirst($run->status)) }}</x-ui.badge>
                                            </td>
                                            <td class="px-table-cell-x py-table-cell-y whitespace-nowrap text-right text-xs text-muted tabular-nums">
                                                <div>{{ __(':count participants', ['count' => $run->participants_count]) }}</div>
                                                <div>{{ __(':count inputs', ['count' => $run->inputs_count]) }}</div>
                                                <div>{{ __(':count result lines', ['count' => $run->result_lines_count]) }}</div>
                                            </td>
                                            <td class="px-table-cell-x py-table-cell-y">
                                                <div class="flex flex-wrap justify-end gap-2">
                                                    @if ($canManage && ! $run->isClosed())
                                                        <x-ui.button size="sm" variant="ghost" wire:click="calculateRun({{ $run->id }})">{{ __('Calculate') }}</x-ui.button>
                                                        <x-ui.button size="sm" variant="ghost" wire:click="transitionPayrollRun('review', {{ $run->id }})">{{ __('Review') }}</x-ui.button>
                                                        <x-ui.button size="sm" variant="ghost" wire:click="transitionPayrollRun('approve', {{ $run->id }})">{{ __('Approve') }}</x-ui.button>
                                                        <x-ui.button size="sm" variant="ghost" wire:click="transitionPayrollRun('close', {{ $run->id }})">{{ __('Close') }}</x-ui.button>
                                                        <x-ui.button size="sm" variant="ghost" wire:click="transitionPayrollRun('void', {{ $run->id }})">{{ __('Void') }}</x-ui.button>
                                                    @elseif (! $canManage)
                                                        <span class="text-xs text-muted">{{ __('View only') }}</span>
                                                    @else
                                                        <span class="text-xs text-muted">{{ __('Locked') }}</span>
                                                    @endif
                                                </div>
                                            </td>
                                        </tr>
                                    @empty
                                        <tr>
                                            <td colspan="5" class="px-table-cell-x py-10 text-center text-sm text-muted">{{ __('No payroll runs found. Run the Payroll dev seeder to create browser fixtures.') }}</td>
                                        </tr>
                                    @endforelse

                        </x-ui.table>

                        <div>{{ $runs->links() }}</div>
                    </div>

                    <div class="space-y-4">
                        @if ($selectedRun)
                            <x-ui.card :title="__('Run Details')">
                                <div class="space-y-3 text-sm">
                                    <div class="flex items-start justify-between gap-3">
                                        <div>
                                            <div class="font-medium text-ink">{{ $selectedRun->name }}</div>
                                            <div class="text-xs text-muted font-mono">{{ $selectedRun->code }}</div>
                                        </div>
                                        <x-ui.badge :variant="$this->statusVariant($selectedRun->status)">{{ __(ucfirst($selectedRun->status)) }}</x-ui.badge>
                                    </div>

                                    <dl class="grid grid-cols-2 gap-3 text-xs">
                                        <div><dt class="text-muted">{{ __('Calendar') }}</dt><dd class="text-ink">{{ $selectedRun->calendar?->name ?? '-' }}</dd></div>
                                        <div><dt class="text-muted">{{ __('Pay date') }}</dt><dd class="text-ink tabular-nums">{{ $selectedRun->period?->pay_date?->toDateString() ?? '-' }}</dd></div>
                                        <div><dt class="text-muted">{{ __('Currency') }}</dt><dd class="text-ink">{{ $selectedRun->currency }}</dd></div>
                                        <div><dt class="text-muted">{{ __('Calculated') }}</dt><dd class="text-ink tabular-nums">{{ $selectedRun->calculated_at?->format('Y-m-d H:i') ?? '-' }}</dd></div>
                                    </dl>
                                </div>
                            </x-ui.card>

                            <x-ui.card :title="__('Payslip Snapshots')">
                                <div class="space-y-4">
                                    @forelse ($payslips as $payslip)
                                        <div class="rounded-xl border border-border-default p-4" wire:key="payslip-{{ $payslip['employee']['id'] }}">
                                            <div class="mb-3 flex items-start justify-between gap-3">
                                                <div>
                                                    <div class="font-medium text-ink">{{ $payslip['employee']['name'] }}</div>
                                                    <div class="text-xs text-muted font-mono">{{ $payslip['employee']['number'] }}</div>
                                                </div>
                                                <div class="text-right text-xs text-muted">
                                                    <div>{{ $payslip['period']['name'] }}</div>
                                                    <div class="tabular-nums">{{ $payslip['period']['pay_date'] }}</div>
                                                </div>
                                            </div>

                                            <div class="grid grid-cols-2 gap-2 text-xs sm:grid-cols-4">
                                                <div><div class="text-muted">{{ __('Gross') }}</div><div class="font-medium tabular-nums text-ink">{{ $payslip['summary']['gross_pay'] }}</div></div>
                                                <div><div class="text-muted">{{ __('Deductions') }}</div><div class="font-medium tabular-nums text-ink">{{ $payslip['summary']['total_deductions'] }}</div></div>
                                                <div><div class="text-muted">{{ __('Reimbursements') }}</div><div class="font-medium tabular-nums text-ink">{{ $payslip['summary']['total_reimbursements'] }}</div></div>
                                                <div><div class="text-muted">{{ __('Net') }}</div><div class="font-semibold tabular-nums text-ink">{{ $payslip['summary']['net_pay'] }}</div></div>
                                            </div>
                                        </div>
                                    @empty
                                        <p class="text-sm text-muted">{{ __('No payslip snapshots yet.') }}</p>
                                    @endforelse
                                </div>
                            </x-ui.card>

                            <x-ui.card :title="__('Audit Events')">
                                <div class="space-y-2 text-sm">
                                    @forelse ($selectedRun->auditEvents as $event)
                                        <div class="rounded-lg bg-surface-subtle p-3" wire:key="audit-{{ $event->id }}">
                                            <div class="flex items-center justify-between gap-3">
                                                <span class="font-medium text-ink">{{ ucfirst($event->action) }}</span>
                                                <span class="text-xs text-muted tabular-nums">{{ $event->occurred_at?->format('Y-m-d H:i') }}</span>
                                            </div>
                                            @if ($event->message)
                                                <div class="mt-1 text-xs text-muted">{{ $event->message }}</div>
                                            @endif
                                        </div>
                                    @empty
                                        <p class="text-sm text-muted">{{ __('No audit events yet.') }}</p>
                                    @endforelse
                                </div>
                            </x-ui.card>
                        @else
                            <x-ui.card>
                                <p class="text-sm text-muted">{{ __('Select a payroll run to inspect participants, payslips, and audit events.') }}</p>
                            </x-ui.card>
                        @endif
                    </div>
                </div>
            @elseif ($tab === 'pay-items')
                @if ($canManage)
                    <div class="mb-6 grid gap-6 xl:grid-cols-2">
                        <x-ui.card :title="__('Create Pay Item')">
                            <form wire:submit="createPayItem" class="space-y-4">
                                <div class="grid gap-4 md:grid-cols-2">
                                    <x-ui.input id="payroll-pay-item-code" wire:model="payItemCode" label="{{ __('Code') }}" required :error="$errors->first('payItemCode')" />
                                    <x-ui.input id="payroll-pay-item-name" wire:model="payItemName" label="{{ __('Name') }}" required :error="$errors->first('payItemName')" />
                                </div>
                                <x-ui.select id="payroll-pay-item-input-type" wire:model="payItemInputType" label="{{ __('Input Type') }}" :error="$errors->first('payItemInputType')">
                                    <option value="earning">{{ __('Earning') }}</option>
                                    <option value="deduction">{{ __('Deduction') }}</option>
                                    <option value="reimbursement">{{ __('Reimbursement') }}</option>
                                </x-ui.select>
                                <x-ui.button type="submit" variant="primary">{{ __('Create Pay Item') }}</x-ui.button>
                            </form>
                        </x-ui.card>

                        <x-ui.card :title="__('Add Classification')">
                            <form wire:submit="createClassification" class="space-y-4">
                                <x-ui.select id="payroll-classification-pay-item" wire:model="classificationPayItemId" label="{{ __('Pay Item') }}" required :error="$errors->first('classificationPayItemId')">
                                    <option value="">{{ __('Select a pay item') }}</option>
                                    @foreach ($payItems as $item)
                                        <option value="{{ $item->id }}">{{ $item->code }} — {{ $item->name }}</option>
                                    @endforeach
                                </x-ui.select>
                                <div class="grid gap-4 md:grid-cols-3">
                                    <x-ui.input id="payroll-classification-country" wire:model="classificationCountryIso" label="{{ __('Country') }}" :error="$errors->first('classificationCountryIso')" />
                                    <x-ui.input id="payroll-classification-key" wire:model="classificationKey" label="{{ __('Key') }}" required :error="$errors->first('classificationKey')" />
                                    <x-ui.input id="payroll-classification-value" wire:model="classificationValue" label="{{ __('Value') }}" required :error="$errors->first('classificationValue')" />
                                </div>
                                <div class="grid gap-4 md:grid-cols-3">
                                    <x-ui.input id="payroll-classification-effective-from" type="date" wire:model="classificationEffectiveFrom" label="{{ __('Effective From') }}" required :error="$errors->first('classificationEffectiveFrom')" />
                                    <x-ui.input id="payroll-classification-source-pack" wire:model="classificationSourcePack" label="{{ __('Source Pack') }}" required :error="$errors->first('classificationSourcePack')" />
                                    <x-ui.input id="payroll-classification-source-version" wire:model="classificationSourceVersion" label="{{ __('Source Version') }}" required :error="$errors->first('classificationSourceVersion')" />
                                </div>
                                <x-ui.button type="submit" variant="primary">{{ __('Save Classification') }}</x-ui.button>
                            </form>
                        </x-ui.card>
                    </div>
                @endif

                <x-ui.table container="flush" :caption="__('Pay items')">


                    <x-slot name="head">
                            <tr>
                                <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Pay Item') }}</th>
                                <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Input Type') }}</th>
                                <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Classifications') }}</th>
                            </tr>
                        </x-slot>

                            @forelse ($payItems as $item)
                                <tr wire:key="pay-item-{{ $item->id }}">
                                    <td class="px-table-cell-x py-table-cell-y">
                                        <div class="font-medium text-ink">{{ $item->name }}</div>
                                        <div class="text-xs text-muted font-mono">{{ $item->code }}</div>
                                    </td>
                                    <td class="px-table-cell-x py-table-cell-y whitespace-nowrap"><x-ui.badge>{{ __(ucfirst($item->input_type)) }}</x-ui.badge></td>
                                    <td class="px-table-cell-x py-table-cell-y">
                                        <div class="flex flex-wrap gap-2">
                                            @foreach ($item->classifications as $classification)
                                                <x-ui.badge variant="info" wire:key="classification-{{ $classification->id }}">
                                                    {{ $classification->country_iso ?? 'CORE' }} · {{ $classification->classification_key }}={{ $classification->classification_value }}
                                                </x-ui.badge>
                                            @endforeach
                                        </div>
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="3" class="px-table-cell-x py-10 text-center text-sm text-muted">{{ __('No pay items found.') }}</td></tr>
                            @endforelse


                </x-ui.table>
            @elseif ($tab === 'profiles')
                @if ($canManage)
                    <div class="mb-6 grid gap-6 xl:grid-cols-2">
                        <x-ui.card :title="__('Save Employer Profile')">
                            <form wire:submit="createEmployerProfile" class="space-y-4">
                                <div class="grid gap-4 md:grid-cols-3">
                                    <x-ui.input id="payroll-employer-profile-country" wire:model="employerProfileCountryIso" label="{{ __('Country') }}" required :error="$errors->first('employerProfileCountryIso')" />
                                    <x-ui.input id="payroll-employer-profile-source-pack" wire:model="employerProfileSourcePack" label="{{ __('Source Pack') }}" required :error="$errors->first('employerProfileSourcePack')" />
                                    <x-ui.input id="payroll-employer-profile-source-version" wire:model="employerProfileSourceVersion" label="{{ __('Source Version') }}" required :error="$errors->first('employerProfileSourceVersion')" />
                                </div>
                                <x-ui.input id="payroll-employer-profile-effective-from" type="date" wire:model="employerProfileEffectiveFrom" label="{{ __('Effective From') }}" required :error="$errors->first('employerProfileEffectiveFrom')" />
                                <x-ui.textarea id="payroll-employer-profile-data" wire:model="employerProfileData" label="{{ __('Profile JSON') }}" rows="8" required :error="$errors->first('employerProfileData')" />
                                <x-ui.button type="submit" variant="primary">{{ __('Save Employer Profile') }}</x-ui.button>
                            </form>
                        </x-ui.card>

                        <x-ui.card :title="__('Save Employee Profile')">
                            <form wire:submit="createEmployeeProfile" class="space-y-4">
                                <x-ui.select id="payroll-employee-profile-employee" wire:model="employeeProfileEmployeeId" label="{{ __('Employee') }}" required :error="$errors->first('employeeProfileEmployeeId')">
                                    <option value="">{{ __('Select an employee') }}</option>
                                    @foreach ($employees as $employee)
                                        <option value="{{ $employee->id }}">{{ $employee->employee_number }} — {{ $employee->displayName() }}</option>
                                    @endforeach
                                </x-ui.select>
                                <div class="grid gap-4 md:grid-cols-3">
                                    <x-ui.input id="payroll-employee-profile-country" wire:model="employeeProfileCountryIso" label="{{ __('Country') }}" required :error="$errors->first('employeeProfileCountryIso')" />
                                    <x-ui.input id="payroll-employee-profile-source-pack" wire:model="employeeProfileSourcePack" label="{{ __('Source Pack') }}" required :error="$errors->first('employeeProfileSourcePack')" />
                                    <x-ui.input id="payroll-employee-profile-source-version" wire:model="employeeProfileSourceVersion" label="{{ __('Source Version') }}" required :error="$errors->first('employeeProfileSourceVersion')" />
                                </div>
                                <x-ui.input id="payroll-employee-profile-effective-from" type="date" wire:model="employeeProfileEffectiveFrom" label="{{ __('Effective From') }}" required :error="$errors->first('employeeProfileEffectiveFrom')" />
                                <x-ui.textarea id="payroll-employee-profile-data" wire:model="employeeProfileData" label="{{ __('Profile JSON') }}" rows="8" required :error="$errors->first('employeeProfileData')" />
                                <x-ui.button type="submit" variant="primary">{{ __('Save Employee Profile') }}</x-ui.button>
                            </form>
                        </x-ui.card>
                    </div>
                @endif

                <div class="mb-6 grid gap-6 xl:grid-cols-2">
                    @forelse ($countryPacks as $countryPack)
                        <x-ui.card :title="__('Country Pack: :country', ['country' => $countryPack['country_iso']])">
                            <div class="space-y-4 text-sm">
                                <div>
                                    <div class="font-medium text-ink">{{ $countryPack['pack_identifier'] }}</div>
                                    <div class="text-xs text-muted">{{ __('Version') }} {{ $countryPack['pack_version'] }} · {{ __('Data') }} {{ implode(', ', $countryPack['statutory_data_versions']) }}</div>
                                </div>

                                <div class="grid gap-4 md:grid-cols-2">
                                    <div>
                                        <div class="mb-2 text-[11px] font-semibold uppercase tracking-wider text-muted">{{ __('Employer fields') }}</div>
                                        <ul class="space-y-1 text-xs text-muted">
                                            @foreach ($countryPack['employer_schema']->fields as $field)
                                                <li class="flex items-start justify-between gap-3">
                                                    <span>{{ $field['label'] }} <span class="font-mono text-muted/80">{{ $field['key'] }}</span></span>
                                                    @if ($field['required'] ?? false)
                                                        <x-ui.badge variant="warning">{{ __('Required') }}</x-ui.badge>
                                                    @endif
                                                </li>
                                            @endforeach
                                        </ul>
                                    </div>

                                    <div>
                                        <div class="mb-2 text-[11px] font-semibold uppercase tracking-wider text-muted">{{ __('Employee fields') }}</div>
                                        <ul class="space-y-1 text-xs text-muted">
                                            @foreach ($countryPack['employee_schema']->fields as $field)
                                                <li class="flex items-start justify-between gap-3">
                                                    <span>{{ $field['label'] }} <span class="font-mono text-muted/80">{{ $field['key'] }}</span></span>
                                                    @if ($field['required'] ?? false)
                                                        <x-ui.badge variant="warning">{{ __('Required') }}</x-ui.badge>
                                                    @endif
                                                </li>
                                            @endforeach
                                        </ul>
                                    </div>
                                </div>
                            </div>
                        </x-ui.card>
                    @empty
                        <x-ui.card>
                            <p class="text-sm text-muted">{{ __('No payroll country packs are registered.') }}</p>
                        </x-ui.card>
                    @endforelse
                </div>

                <div class="grid gap-6 xl:grid-cols-2">
                    <x-ui.card :title="__('Employer Profiles')">
                        <div class="space-y-3">
                            @forelse ($employerProfiles as $profile)
                                <div class="rounded-xl border border-border-default p-4" wire:key="employer-profile-{{ $profile->id }}">
                                    <div class="mb-2 flex items-center justify-between gap-3">
                                        <x-ui.badge variant="accent">{{ $profile->country_iso }}</x-ui.badge>
                                        <span class="text-xs text-muted tabular-nums">{{ $profile->effective_from->toDateString() }} → {{ $profile->effective_to?->toDateString() ?? __('open') }}</span>
                                    </div>
                                    <div class="text-xs text-muted">{{ $profile->source_pack }} · {{ $profile->source_version }}</div>
                                    <pre class="mt-3 overflow-x-auto rounded-lg bg-surface-subtle p-3 text-xs text-ink">{{ json_encode($profile->profile_data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
                                </div>
                            @empty
                                <p class="text-sm text-muted">{{ __('No employer statutory profiles found.') }}</p>
                            @endforelse
                        </div>
                    </x-ui.card>

                    <x-ui.card :title="__('Employee Profiles')">
                        <div class="space-y-3">
                            @forelse ($employeeProfiles as $profile)
                                <div class="rounded-xl border border-border-default p-4" wire:key="employee-profile-{{ $profile->id }}">
                                    <div class="mb-2 flex items-center justify-between gap-3">
                                        <div>
                                            <div class="font-medium text-ink">{{ $profile->employee?->displayName() ?? __('Unknown employee') }}</div>
                                            <div class="text-xs text-muted font-mono">{{ $profile->employee?->employee_number }}</div>
                                        </div>
                                        <x-ui.badge variant="accent">{{ $profile->country_iso }}</x-ui.badge>
                                    </div>
                                    <div class="text-xs text-muted">{{ $profile->source_pack }} · {{ $profile->source_version }} · {{ $profile->effective_from->toDateString() }}</div>
                                    <pre class="mt-3 overflow-x-auto rounded-lg bg-surface-subtle p-3 text-xs text-ink">{{ json_encode($profile->profile_data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
                                </div>
                            @empty
                                <p class="text-sm text-muted">{{ __('No employee statutory profiles found.') }}</p>
                            @endforelse
                        </div>
                    </x-ui.card>
                </div>
            @else
                @if ($canManage)
                    <div class="mb-6 grid gap-6 xl:grid-cols-2">
                        <x-ui.card :title="__('Save Rule Set')">
                            <form wire:submit="createRuleSet" class="space-y-4">
                                <div class="grid gap-4 md:grid-cols-2">
                                    <x-ui.input id="payroll-rule-set-country" wire:model="ruleSetCountryIso" label="{{ __('Country') }}" required :error="$errors->first('ruleSetCountryIso')" />
                                    <x-ui.input id="payroll-rule-set-rule-key" wire:model="ruleSetRuleKey" label="{{ __('Rule Key') }}" required :error="$errors->first('ruleSetRuleKey')" />
                                </div>
                                <x-ui.input id="payroll-rule-set-name" wire:model="ruleSetName" label="{{ __('Name') }}" required :error="$errors->first('ruleSetName')" />
                                <div class="grid gap-4 md:grid-cols-3">
                                    <x-ui.input id="payroll-rule-set-source-pack" wire:model="ruleSetSourcePack" label="{{ __('Source Pack') }}" required :error="$errors->first('ruleSetSourcePack')" />
                                    <x-ui.input id="payroll-rule-set-source-version" wire:model="ruleSetSourceVersion" label="{{ __('Source Version') }}" required :error="$errors->first('ruleSetSourceVersion')" />
                                    <x-ui.input id="payroll-rule-set-effective-from" type="date" wire:model="ruleSetEffectiveFrom" label="{{ __('Effective From') }}" required :error="$errors->first('ruleSetEffectiveFrom')" />
                                </div>
                                <x-ui.textarea id="payroll-rule-set-rounding-policy" wire:model="ruleSetRoundingPolicy" label="{{ __('Rounding Policy JSON') }}" rows="3" :error="$errors->first('ruleSetRoundingPolicy')" />
                                <x-ui.button type="submit" variant="primary">{{ __('Save Rule Set') }}</x-ui.button>
                            </form>
                        </x-ui.card>

                        <x-ui.card :title="__('Add Rule Row')">
                            <form wire:submit="createRuleRow" class="space-y-4">
                                <x-ui.select id="payroll-rule-row-rule-set" wire:model="ruleRowRuleSetId" label="{{ __('Rule Set') }}" required :error="$errors->first('ruleRowRuleSetId')">
                                    <option value="">{{ __('Select a rule set') }}</option>
                                    @foreach ($ruleSets as $ruleSet)
                                        <option value="{{ $ruleSet->id }}">{{ $ruleSet->country_iso }} · {{ $ruleSet->rule_key }} · {{ $ruleSet->source_version }}</option>
                                    @endforeach
                                </x-ui.select>
                                <x-ui.input id="payroll-rule-row-key" wire:model="ruleRowKey" label="{{ __('Row Key') }}" :error="$errors->first('ruleRowKey')" />
                                <div class="grid gap-4 md:grid-cols-2">
                                    <x-ui.input id="payroll-rule-row-min-wage" wire:model="ruleRowMinWage" label="{{ __('Min Wage') }}" :error="$errors->first('ruleRowMinWage')" />
                                    <x-ui.input id="payroll-rule-row-max-wage" wire:model="ruleRowMaxWage" label="{{ __('Max Wage') }}" :error="$errors->first('ruleRowMaxWage')" />
                                    <x-ui.input id="payroll-rule-row-employee-rate" wire:model="ruleRowEmployeeRate" label="{{ __('Employee Rate') }}" :error="$errors->first('ruleRowEmployeeRate')" />
                                    <x-ui.input id="payroll-rule-row-employer-rate" wire:model="ruleRowEmployerRate" label="{{ __('Employer Rate') }}" :error="$errors->first('ruleRowEmployerRate')" />
                                    <x-ui.input id="payroll-rule-row-levy-rate" wire:model="ruleRowLevyRate" label="{{ __('Levy Rate') }}" :error="$errors->first('ruleRowLevyRate')" />
                                </div>
                                <x-ui.button type="submit" variant="primary">{{ __('Add Rule Row') }}</x-ui.button>
                            </form>
                        </x-ui.card>
                    </div>
                @endif

                <div class="space-y-4">
                    @forelse ($ruleSets as $ruleSet)
                        <div class="rounded-2xl border border-border-default p-4" wire:key="rule-set-{{ $ruleSet->id }}">
                            <div class="mb-3 flex flex-col gap-2 lg:flex-row lg:items-start lg:justify-between">
                                <div>
                                    <div class="font-medium text-ink">{{ $ruleSet->name }}</div>
                                    <div class="text-xs text-muted font-mono">{{ $ruleSet->country_iso }} · {{ $ruleSet->rule_key }}</div>
                                </div>
                                <div class="text-xs text-muted lg:text-right">
                                    <div>{{ $ruleSet->source_pack }} · {{ $ruleSet->source_version }}</div>
                                    <div class="tabular-nums">{{ $ruleSet->effective_from->toDateString() }} → {{ $ruleSet->effective_to?->toDateString() ?? __('open') }}</div>
                                </div>
                            </div>

                            <x-ui.table container="plain" size="xs" :caption="__('Rule set rows')">


                                <x-slot name="head">
                                        <tr>
                                            <th class="px-table-cell-x py-table-header-y text-left font-semibold text-muted uppercase tracking-wider">{{ __('Row') }}</th>
                                            <th class="px-table-cell-x py-table-header-y text-right font-semibold text-muted uppercase tracking-wider">{{ __('Min') }}</th>
                                            <th class="px-table-cell-x py-table-header-y text-right font-semibold text-muted uppercase tracking-wider">{{ __('Max') }}</th>
                                            <th class="px-table-cell-x py-table-header-y text-right font-semibold text-muted uppercase tracking-wider">{{ __('Employee Rate') }}</th>
                                            <th class="px-table-cell-x py-table-header-y text-right font-semibold text-muted uppercase tracking-wider">{{ __('Employer Rate') }}</th>
                                            <th class="px-table-cell-x py-table-header-y text-right font-semibold text-muted uppercase tracking-wider">{{ __('Levy Rate') }}</th>
                                        </tr>
                                    </x-slot>

                                        @foreach ($ruleSet->rows as $row)
                                            <tr wire:key="rule-row-{{ $row->id }}">
                                                <td class="px-table-cell-x py-table-cell-y font-mono text-muted">{{ $row->row_key ?? $row->id }}</td>
                                                <td class="px-table-cell-x py-table-cell-y text-right tabular-nums text-ink">{{ $row->min_wage ?? '-' }}</td>
                                                <td class="px-table-cell-x py-table-cell-y text-right tabular-nums text-ink">{{ $row->max_wage ?? '-' }}</td>
                                                <td class="px-table-cell-x py-table-cell-y text-right tabular-nums text-ink">{{ $row->employee_rate ?? '-' }}</td>
                                                <td class="px-table-cell-x py-table-cell-y text-right tabular-nums text-ink">{{ $row->employer_rate ?? '-' }}</td>
                                                <td class="px-table-cell-x py-table-cell-y text-right tabular-nums text-ink">{{ $row->levy_rate ?? '-' }}</td>
                                            </tr>
                                        @endforeach
                                    
                            </x-ui.table>
                        </div>
                    @empty
                        <p class="text-sm text-muted">{{ __('No statutory rule tables found.') }}</p>
                    @endforelse
                </div>
            @endif
        </x-ui.card>
    </div>
</div>
