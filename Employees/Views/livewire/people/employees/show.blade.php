<div>
    <x-slot name="title">{{ $employee->displayName() }}</x-slot>

    @php($workProfile = $employee->workProfile)
    @php($portalAccess = $employee->portalAccess)

    <div class="space-y-section-gap">
        <x-ui.page-header
            :title="$employee->displayName()"
            :subtitle="$employee->designation ?: $employee->employee_number"
        >
            <x-slot name="actions">
                <x-ui.record-history
                    :title="__('History for :name', ['name' => $employee->displayName()])"
                    :subjects="[['name' => 'employee', 'id' => $employee->id]]"
                    :auditable-type="$employee->getMorphClass()"
                    :auditable-id="$employee->id"
                    source-capability="people.employee.view"
                />
                <a href="{{ route('people.employees.index') }}" wire:navigate class="inline-flex items-center gap-2 px-4 py-2 rounded-2xl text-accent hover:bg-surface-subtle transition-colors">
                    <x-icon name="heroicon-o-arrow-left" class="w-5 h-5" />
                    {{ __('Back to Workbench') }}
                </a>
            </x-slot>
        </x-ui.page-header>

        @if (session('success'))
            <x-ui.alert variant="success">{{ session('success') }}</x-ui.alert>
        @endif

        <div class="grid gap-4 xl:grid-cols-3">
            <x-ui.card class="xl:col-span-2">
                <h3 class="text-[11px] uppercase tracking-wider font-semibold text-muted mb-4">{{ __('Employee Summary') }}</h3>

                <dl class="grid gap-4 md:grid-cols-3">
                    <div>
                        <dt class="text-[11px] uppercase tracking-wider font-semibold text-muted">{{ __('Company') }}</dt>
                        <dd class="text-sm text-default">{{ $employee->company?->name ?? '-' }}</dd>
                    </div>
                    <div>
                        <dt class="text-[11px] uppercase tracking-wider font-semibold text-muted">{{ __('Supervisor') }}</dt>
                        <dd class="text-sm text-default">{{ $employee->supervisor?->displayName() ?? __('None') }}</dd>
                    </div>
                    <div>
                        <dt class="text-[11px] uppercase tracking-wider font-semibold text-muted">{{ __('User Link') }}</dt>
                        <dd class="text-sm text-default">{{ $employee->user?->email ?? __('None') }}</dd>
                    </div>
                </dl>
            </x-ui.card>

            <x-ui.card>
                <h3 class="text-[11px] uppercase tracking-wider font-semibold text-muted mb-4">{{ __('Payroll Readiness') }}</h3>

                <x-ui.badge :variant="$this->statusVariant($readiness['state'])">{{ ucfirst($readiness['state']) }}</x-ui.badge>

                <div class="mt-4 space-y-3">
                    @forelse($readiness['blockers'] as $blocker)
                        <div class="rounded-2xl border border-border-default bg-surface-subtle/40 p-3">
                            <div class="text-sm font-medium text-default">{{ $blocker['label'] }}</div>
                            <div class="text-xs text-muted">{{ $blocker['detail'] }}</div>
                        </div>
                    @empty
                        <p class="text-sm text-muted">{{ __('Work profile, bank details, statutory profile, and active status are ready.') }}</p>
                    @endforelse
                </div>
            </x-ui.card>
        </div>

        <div class="grid gap-4 xl:grid-cols-2">
            <x-ui.card>
                <div class="flex items-center justify-between mb-4">
                    <h3 class="text-[11px] uppercase tracking-wider font-semibold text-muted">{{ __('HR Details') }}</h3>
                    <x-ui.badge :variant="$this->statusVariant($employee->status)">{{ ucfirst($employee->status) }}</x-ui.badge>
                </div>

                <form wire:submit="saveEmployeeDetails" class="space-y-4">
                    <div class="grid gap-4 md:grid-cols-2">
                        <label class="space-y-1 block">
                            <span class="text-[11px] uppercase tracking-wider font-semibold text-muted">{{ __('Full Name') }}</span>
                            <input wire:model="fullName" type="text" class="w-full rounded-2xl border border-border-default bg-surface-card px-3 py-2 text-sm text-default" />
                        </label>
                        <label class="space-y-1 block">
                            <span class="text-[11px] uppercase tracking-wider font-semibold text-muted">{{ __('Short Name') }}</span>
                            <input wire:model="shortName" type="text" class="w-full rounded-2xl border border-border-default bg-surface-card px-3 py-2 text-sm text-default" />
                        </label>
                        <label class="space-y-1 block">
                            <span class="text-[11px] uppercase tracking-wider font-semibold text-muted">{{ __('Employee Number') }}</span>
                            <input wire:model="employeeNumber" type="text" class="w-full rounded-2xl border border-border-default bg-surface-card px-3 py-2 text-sm text-default" />
                        </label>
                        <label class="space-y-1 block">
                            <span class="text-[11px] uppercase tracking-wider font-semibold text-muted">{{ __('Status') }}</span>
                            <select wire:model="status" class="w-full rounded-2xl border border-border-default bg-surface-card px-3 py-2 text-sm text-default">
                                <option value="pending">{{ __('Pending') }}</option>
                                <option value="probation">{{ __('Probation') }}</option>
                                <option value="active">{{ __('Active') }}</option>
                                <option value="inactive">{{ __('Inactive') }}</option>
                                <option value="terminated">{{ __('Terminated') }}</option>
                            </select>
                        </label>
                        <label class="space-y-1 block">
                            <span class="text-[11px] uppercase tracking-wider font-semibold text-muted">{{ __('Designation') }}</span>
                            <input wire:model="designation" type="text" class="w-full rounded-2xl border border-border-default bg-surface-card px-3 py-2 text-sm text-default" />
                        </label>
                        <label class="space-y-1 block">
                            <span class="text-[11px] uppercase tracking-wider font-semibold text-muted">{{ __('Email') }}</span>
                            <input wire:model="email" type="email" class="w-full rounded-2xl border border-border-default bg-surface-card px-3 py-2 text-sm text-default" />
                        </label>
                        <label class="space-y-1 block md:col-span-2">
                            <span class="text-[11px] uppercase tracking-wider font-semibold text-muted">{{ __('Mobile Number') }}</span>
                            <input wire:model="mobileNumber" type="text" class="w-full rounded-2xl border border-border-default bg-surface-card px-3 py-2 text-sm text-default" />
                        </label>
                    </div>

                    <x-ui.button type="submit" variant="primary">{{ __('Save HR Details') }}</x-ui.button>
                </form>
            </x-ui.card>

            <x-ui.card>
                <div class="flex items-center justify-between mb-4">
                    <h3 class="text-[11px] uppercase tracking-wider font-semibold text-muted">{{ __('Work Profile') }}</h3>
                    @if($workProfile)
                        <x-ui.badge variant="success">{{ __('Present') }}</x-ui.badge>
                    @else
                        <x-ui.badge variant="warning">{{ __('Not configured') }}</x-ui.badge>
                    @endif
                </div>

                <form wire:submit="saveWorkProfile" class="space-y-4">
                    <div class="grid gap-4 md:grid-cols-2">
                        <label class="space-y-1 block">
                            <span class="text-[11px] uppercase tracking-wider font-semibold text-muted">{{ __('Cost Center') }}</span>
                            <select wire:model="costCenterId" class="w-full rounded-2xl border border-border-default bg-surface-card px-3 py-2 text-sm text-default">
                                <option value="">{{ __('None') }}</option>
                                @foreach($costCenters as $entry)
                                    <option value="{{ $entry->id }}">{{ $entry->name }}{{ $entry->status === 'inactive' ? ' (inactive)' : '' }}</option>
                                @endforeach
                            </select>
                        </label>
                        <label class="space-y-1 block">
                            <span class="text-[11px] uppercase tracking-wider font-semibold text-muted">{{ __('Organization Unit') }}</span>
                            <select wire:model="organizationUnitId" class="w-full rounded-2xl border border-border-default bg-surface-card px-3 py-2 text-sm text-default">
                                <option value="">{{ __('None') }}</option>
                                @foreach($organizationUnits as $entry)
                                    <option value="{{ $entry->id }}">{{ $entry->name }}{{ $entry->status === 'inactive' ? ' (inactive)' : '' }}</option>
                                @endforeach
                            </select>
                        </label>
                        <label class="space-y-1 block">
                            <span class="text-[11px] uppercase tracking-wider font-semibold text-muted">{{ __('Employment Group') }}</span>
                            <select wire:model="employmentGroupId" class="w-full rounded-2xl border border-border-default bg-surface-card px-3 py-2 text-sm text-default">
                                <option value="">{{ __('None') }}</option>
                                @foreach($employmentGroups as $entry)
                                    <option value="{{ $entry->id }}">{{ $entry->name }}{{ $entry->status === 'inactive' ? ' (inactive)' : '' }}</option>
                                @endforeach
                            </select>
                        </label>
                        <label class="space-y-1 block">
                            <span class="text-[11px] uppercase tracking-wider font-semibold text-muted">{{ __('Job Title') }}</span>
                            <select wire:model="jobTitleId" class="w-full rounded-2xl border border-border-default bg-surface-card px-3 py-2 text-sm text-default">
                                <option value="">{{ __('None') }}</option>
                                @foreach($jobTitles as $entry)
                                    <option value="{{ $entry->id }}">{{ $entry->name }}{{ $entry->status === 'inactive' ? ' (inactive)' : '' }}</option>
                                @endforeach
                            </select>
                        </label>
                        <label class="space-y-1 block">
                            <span class="text-[11px] uppercase tracking-wider font-semibold text-muted">{{ __('Workforce Class') }}</span>
                            <select wire:model="workforceClassId" class="w-full rounded-2xl border border-border-default bg-surface-card px-3 py-2 text-sm text-default">
                                <option value="">{{ __('None') }}</option>
                                @foreach($workforceClasses as $entry)
                                    <option value="{{ $entry->id }}">{{ $entry->name }}{{ $entry->status === 'inactive' ? ' (inactive)' : '' }}</option>
                                @endforeach
                            </select>
                        </label>
                        <label class="space-y-1 block">
                            <span class="text-[11px] uppercase tracking-wider font-semibold text-muted">{{ __('Job Grade') }}</span>
                            <select wire:model="jobGradeId" class="w-full rounded-2xl border border-border-default bg-surface-card px-3 py-2 text-sm text-default">
                                <option value="">{{ __('None') }}</option>
                                @foreach($jobGrades as $entry)
                                    <option value="{{ $entry->id }}">{{ $entry->name }}{{ $entry->status === 'inactive' ? ' (inactive)' : '' }}</option>
                                @endforeach
                            </select>
                        </label>
                        <label class="space-y-1 block">
                            <span class="text-[11px] uppercase tracking-wider font-semibold text-muted">{{ __('Work Calendar') }}</span>
                            <select wire:model="workCalendarId" class="w-full rounded-2xl border border-border-default bg-surface-card px-3 py-2 text-sm text-default">
                                <option value="">{{ __('None') }}</option>
                                @foreach($workCalendars as $entry)
                                    <option value="{{ $entry->id }}">{{ $entry->name }}{{ $entry->status === 'inactive' ? ' (inactive)' : '' }}</option>
                                @endforeach
                            </select>
                        </label>
                        <label class="space-y-1 block">
                            <span class="text-[11px] uppercase tracking-wider font-semibold text-muted">{{ __('Pay Basis') }}</span>
                            <select wire:model="payRateType" class="w-full rounded-2xl border border-border-default bg-surface-card px-3 py-2 text-sm text-default">
                                <option value="">{{ __('None') }}</option>
                                <option value="monthly">{{ __('Monthly') }}</option>
                                <option value="daily">{{ __('Daily') }}</option>
                                <option value="hourly">{{ __('Hourly') }}</option>
                                <option value="piece_rate">{{ __('Piece rate') }}</option>
                            </select>
                        </label>
                        <label class="space-y-1 block">
                            <span class="text-[11px] uppercase tracking-wider font-semibold text-muted">{{ __('Hired On') }}</span>
                            <input wire:model="hiredOn" type="date" class="w-full rounded-2xl border border-border-default bg-surface-card px-3 py-2 text-sm text-default" />
                        </label>
                        <label class="space-y-1 block">
                            <span class="text-[11px] uppercase tracking-wider font-semibold text-muted">{{ __('Resigned On') }}</span>
                            <input wire:model="resignedOn" type="date" class="w-full rounded-2xl border border-border-default bg-surface-card px-3 py-2 text-sm text-default" />
                        </label>
                    </div>

                    <div class="rounded-2xl border border-border-default bg-surface-subtle/40 p-3 text-xs text-muted">
                        <div>{{ __('Current source evidence stays on the referenced settings entries so imported iPayroll labels and codes remain explainable.') }}</div>
                        @if($workProfile?->jobTitle?->source_code)
                            <div class="mt-1">{{ __('Job title source code') }}: {{ $workProfile->jobTitle->source_code }}</div>
                        @endif
                        @if($workProfile?->employmentGroup?->source_code)
                            <div>{{ __('Employment group source code') }}: {{ $workProfile->employmentGroup->source_code }}</div>
                        @endif
                    </div>

                    <x-ui.button type="submit" variant="primary">{{ __('Save Work Profile') }}</x-ui.button>
                </form>
            </x-ui.card>
        </div>

        <div class="grid gap-4 xl:grid-cols-2">
            <x-ui.card>
                <div class="flex items-center justify-between mb-4">
                    <h3 class="text-[11px] uppercase tracking-wider font-semibold text-muted">{{ __('Employee Account Access') }}</h3>
                    <x-ui.badge :variant="$this->statusVariant($portalAccess?->status ?? 'pending')">
                        {{ $portalAccess?->status ? ucfirst($portalAccess->status) : __('Unprovisioned') }}
                    </x-ui.badge>
                </div>

                <div class="space-y-4">
                    <div class="grid gap-4 md:grid-cols-2">
                        <label class="space-y-1 block">
                            <span class="text-[11px] uppercase tracking-wider font-semibold text-muted">{{ __('Login Identifier') }}</span>
                            <input wire:model="accessLoginIdentifier" type="text" class="w-full rounded-2xl border border-border-default bg-surface-card px-3 py-2 text-sm text-default" />
                        </label>
                        <label class="space-y-1 block">
                            <span class="text-[11px] uppercase tracking-wider font-semibold text-muted">{{ __('Access Email') }}</span>
                            <input wire:model="accessEmail" type="email" class="w-full rounded-2xl border border-border-default bg-surface-card px-3 py-2 text-sm text-default" />
                        </label>
                    </div>

                    <div class="flex flex-wrap gap-2">
                        <x-ui.button type="button" variant="primary" wire:click="provisionAccess">{{ __('Provision') }}</x-ui.button>
                        <x-ui.button type="button" variant="ghost" wire:click="sendAccessInvitation">{{ __('Send Invitation') }}</x-ui.button>
                        @if($portalAccess)
                            <x-ui.button type="button" variant="ghost" wire:click="activateAccess">{{ __('Activate') }}</x-ui.button>
                            <x-ui.button type="button" variant="danger-ghost" wire:click="revokeAccess">{{ __('Revoke') }}</x-ui.button>
                        @endif
                    </div>

                    <dl class="grid gap-4 md:grid-cols-2">
                        <div>
                            <dt class="text-[11px] uppercase tracking-wider font-semibold text-muted">{{ __('Linked User') }}</dt>
                            <dd class="text-sm text-default">{{ $portalAccess?->user?->email ?? $employee->user?->email ?? __('None') }}</dd>
                        </div>
                        <div>
                            <dt class="text-[11px] uppercase tracking-wider font-semibold text-muted">{{ __('Last Invitation') }}</dt>
                            <dd class="text-sm text-default">
                                @if($portalAccess?->last_invited_at)
                                    <x-ui.datetime :value="$portalAccess->last_invited_at" format="datetime" />
                                @else
                                    {{ __('Never') }}
                                @endif
                            </dd>
                        </div>
                    </dl>

                    <div class="space-y-2">
                        <h4 class="text-[11px] uppercase tracking-wider font-semibold text-muted">{{ __('Notification History') }}</h4>
                        @forelse($notificationLogs as $log)
                            <div class="rounded-2xl border border-border-default bg-surface-subtle/40 p-3">
                                <div class="text-sm text-default">{{ $log->subject ?? __('Notification') }}</div>
                                <div class="text-xs text-muted">{{ $log->recipient ?? __('No recipient') }} - {{ ucfirst($log->status) }}</div>
                            </div>
                        @empty
                            <p class="text-sm text-muted">{{ __('No access notifications logged yet.') }}</p>
                        @endforelse
                    </div>
                </div>
            </x-ui.card>

            <x-ui.card>
                <h3 class="text-[11px] uppercase tracking-wider font-semibold text-muted mb-4">{{ __('Payroll Detail Signals') }}</h3>

                <dl class="grid gap-4 md:grid-cols-2">
                    <div>
                        <dt class="text-[11px] uppercase tracking-wider font-semibold text-muted">{{ __('Bank Name') }}</dt>
                        <dd class="text-sm text-default">{{ $readiness['bank']['bank_name'] ?: __('Missing') }}</dd>
                    </div>
                    <div>
                        <dt class="text-[11px] uppercase tracking-wider font-semibold text-muted">{{ __('Bank Account Number') }}</dt>
                        <dd class="text-sm text-default">{{ $readiness['bank']['bank_account_number'] ?: __('Missing') }}</dd>
                    </div>
                    <div>
                        <dt class="text-[11px] uppercase tracking-wider font-semibold text-muted">{{ __('Statutory Country') }}</dt>
                        <dd class="text-sm text-default">{{ $readiness['statutory_profile']['country_iso'] ?: __('Missing') }}</dd>
                    </div>
                    <div>
                        <dt class="text-[11px] uppercase tracking-wider font-semibold text-muted">{{ __('Validation Messages') }}</dt>
                        <dd class="text-sm text-default">
                            @if(($readiness['statutory_profile']['validation_messages'] ?? []) !== [])
                                {{ implode('; ', $readiness['statutory_profile']['validation_messages']) }}
                            @else
                                {{ __('None') }}
                            @endif
                        </dd>
                    </div>
                </dl>
            </x-ui.card>
        </div>

        <div class="grid gap-4 xl:grid-cols-2">
            <x-ui.card>
                <div class="flex items-center justify-between mb-4">
                    <h3 class="text-[11px] uppercase tracking-wider font-semibold text-muted">{{ __('Profile Change Requests') }}</h3>
                    <x-ui.badge>{{ $employee->profileChangeRequests->count() }}</x-ui.badge>
                </div>

                <div class="space-y-4">
                    @forelse($employee->profileChangeRequests as $request)
                        <div class="rounded-2xl border border-border-default bg-surface-subtle/40 p-4 space-y-3">
                            <div class="flex items-start justify-between gap-4">
                                <div>
                                    <div class="text-sm font-medium text-default">{{ ucfirst(str_replace('_', ' ', $request->request_type)) }}</div>
                                    <div class="text-xs text-muted">
                                        {{ $request->requestedBy?->email ?? __('Employee request') }}
                                        @if($request->submitted_at)
                                            - <x-ui.datetime :value="$request->submitted_at" format="datetime" />
                                        @endif
                                    </div>
                                </div>
                                <x-ui.badge :variant="$this->statusVariant($request->status)">{{ ucfirst($request->status) }}</x-ui.badge>
                            </div>

                            <div class="space-y-2">
                                @foreach($this->requestChangeGroups($request->requested_changes ?? []) as $group)
                                    <div>
                                        <div class="text-[11px] uppercase tracking-wider font-semibold text-muted">{{ __($group['label']) }}</div>
                                        <div class="mt-1 space-y-1">
                                            @foreach($group['changes'] as $field => $value)
                                                <div class="text-sm text-default">
                                                    <span class="font-medium">{{ ucwords(str_replace('_', ' ', $field)) }}:</span>
                                                    <span class="text-muted">{{ is_scalar($value) ? (string) $value : json_encode($value) }}</span>
                                                </div>
                                            @endforeach
                                        </div>
                                    </div>
                                @endforeach
                            </div>

                            @if($request->status === 'submitted')
                                <label class="space-y-1 block">
                                    <span class="text-[11px] uppercase tracking-wider font-semibold text-muted">{{ __('Review Note') }}</span>
                                    <textarea wire:model="requestReviewNotes.{{ $request->id }}" rows="2" class="w-full rounded-2xl border border-border-default bg-surface-card px-3 py-2 text-sm text-default"></textarea>
                                </label>

                                <div class="flex gap-2">
                                    <x-ui.button type="button" variant="primary" wire:click="approveRequest({{ $request->id }})">{{ __('Approve') }}</x-ui.button>
                                    <x-ui.button type="button" variant="danger-ghost" wire:click="rejectRequest({{ $request->id }})">{{ __('Reject') }}</x-ui.button>
                                </div>
                            @else
                                <div class="text-xs text-muted">
                                    {{ __('Reviewed by') }}: {{ $request->reviewedBy?->email ?? __('Unknown') }}
                                </div>
                            @endif
                        </div>
                    @empty
                        <p class="text-sm text-muted">{{ __('No employee profile change requests for this employee.') }}</p>
                    @endforelse
                </div>
            </x-ui.card>

            <x-ui.card>
                <h3 class="text-[11px] uppercase tracking-wider font-semibold text-muted mb-4">{{ __('Addresses and Team Context') }}</h3>

                <div class="space-y-4">
                    <div>
                        <div class="text-[11px] uppercase tracking-wider font-semibold text-muted mb-2">{{ __('Addresses') }}</div>
                        @forelse($employee->addresses as $address)
                            <div class="rounded-2xl border border-border-default bg-surface-subtle/40 p-3 mb-2">
                                <div class="text-sm text-default">{{ $address->label ?? $address->line1 ?? __('Address') }}</div>
                                <div class="text-xs text-muted">{{ $address->line1 }} {{ $address->locality }}</div>
                            </div>
                        @empty
                            <p class="text-sm text-muted">{{ __('No addresses linked.') }}</p>
                        @endforelse
                    </div>

                    <div>
                        <div class="text-[11px] uppercase tracking-wider font-semibold text-muted mb-2">{{ __('Subordinates') }}</div>
                        @forelse($employee->subordinates as $subordinate)
                            <a href="{{ route('people.employees.show', $subordinate) }}" wire:navigate class="flex items-center justify-between rounded-2xl border border-border-default bg-surface-subtle/40 px-3 py-2 mb-2">
                                <span class="text-sm text-default">{{ $subordinate->displayName() }}</span>
                                <span class="text-xs text-muted">{{ $subordinate->designation ?? __('No designation') }}</span>
                            </a>
                        @empty
                            <p class="text-sm text-muted">{{ __('No direct subordinates.') }}</p>
                        @endforelse
                    </div>
                </div>
            </x-ui.card>
        </div>
    </div>
</div>
