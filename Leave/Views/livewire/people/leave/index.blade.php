<?php
/** @var \App\Modules\People\Leave\Livewire\Index $this */
?>
<div>
    <x-slot name="title">{{ __('Leave') }}</x-slot>

    <div class="space-y-section-gap">
        <x-ui.page-header :title="$surfaceTitle" :subtitle="$surfaceSubtitle">
            <x-slot name="help">
                {{ __('Leave Core is country-neutral. Statutory leave types, entitlement floors, and public holidays come from the registered Leave Country Pack.') }}
            </x-slot>
        </x-ui.page-header>

        @if (session('success'))
            <x-ui.alert variant="success">{{ session('success') }}</x-ui.alert>
        @endif

        @if (session('error'))
            <x-ui.alert variant="danger">{{ session('error') }}</x-ui.alert>
        @endif

        <x-ui.card>
            @if ($surface !== 'settings' || $tab === 'types')
                <div class="mb-4 flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
                    @if ($surface !== 'settings')
                        <x-ui.tabs
                            :tabs="$tabs"
                            :default="$tab"
                            size="sm"
                            persistence="none"
                            wire-action="setTab"
                            class="w-full lg:w-auto"
                        >
                            @foreach ($tabs as $tabDef)
                                <x-ui.tab :id="$tabDef['id']" />
                            @endforeach
                        </x-ui.tabs>
                    @else
                        <span></span>
                    @endif

                    @if ($tab === 'types')
                        <div class="flex flex-col items-stretch gap-2 lg:flex-row lg:items-center">
                            <div class="w-full lg:w-72">
                                <x-ui.search-input
                                    wire:model.live.debounce.300ms="search"
                                    placeholder="{{ __('Search leave types...') }}"
                                />
                            </div>
                            @if ($canManage)
                                <x-ui.button variant="primary" wire:click="$set('showLeaveTypeModal', true)">
                                    <x-icon name="heroicon-o-plus" class="w-4 h-4" />
                                    {{ __('New Leave Type') }}
                                </x-ui.button>
                            @endif
                        </div>
                    @endif
                </div>
            @endif

            {{-- ====================== Apply Leave (employee self-service) ====================== --}}
            @if ($tab === 'apply')
                @if ($currentEmployeeId === null)
                    <x-ui.alert variant="warning">{{ __('Your user account is not linked to an employee record. Ask an administrator to link it before applying for leave.') }}</x-ui.alert>
                @else
                    <div class="mb-4 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                        <div class="text-sm text-muted">{{ __('Your leave requests are listed below. Use New Leave when you need to submit a request.') }}</div>
                        <x-ui.button variant="primary" wire:click="$set('showApplyModal', true)">
                            <x-icon name="heroicon-o-plus" class="w-4 h-4" />
                            {{ __('New Leave') }}
                        </x-ui.button>
                    </div>

                    <div class="overflow-x-auto -mx-card-inner px-card-inner">
                        <table class="min-w-full divide-y divide-border-default text-sm">
                            <thead class="bg-surface-subtle/80">
                                <tr>
                                    <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Leave Type') }}</th>
                                    <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Period') }}</th>
                                    <th class="px-table-cell-x py-table-header-y text-right text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Qty') }}</th>
                                    <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Status') }}</th>
                                    <th class="px-table-cell-x py-table-header-y text-right text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Actions') }}</th>
                                </tr>
                            </thead>
                            <tbody class="bg-surface-card divide-y divide-border-default">
                                @forelse ($myRequests as $request)
                                    <tr wire:key="my-recent-{{ $request->id }}">
                                        <td class="px-table-cell-x py-table-cell-y">
                                            <div class="font-medium text-ink">{{ $request->leaveType?->name }}</div>
                                            <div class="text-xs text-muted font-mono">{{ $request->leaveType?->code }}</div>
                                        </td>
                                        <td class="px-table-cell-x py-table-cell-y text-xs text-muted font-mono tabular-nums">{{ $request->starts_on?->toDateString() }} &rarr; {{ $request->ends_on?->toDateString() }}</td>
                                        <td class="px-table-cell-x py-table-cell-y text-right text-xs text-ink tabular-nums">{{ $request->quantity }} {{ $request->unit }}</td>
                                        <td class="px-table-cell-x py-table-cell-y"><x-ui.badge :variant="$this->statusVariant($request->status)">{{ __(ucfirst($request->status)) }}</x-ui.badge></td>
                                        <td class="px-table-cell-x py-table-cell-y">
                                            <div class="flex justify-end">
                                                @if (in_array($request->status, [\App\Modules\People\Leave\Models\LeaveRequest::STATUS_DRAFT, \App\Modules\People\Leave\Models\LeaveRequest::STATUS_SUBMITTED], true))
                                                    <x-ui.button size="sm" variant="secondary" wire:click="withdrawOwnRequest({{ $request->id }})">{{ __('Withdraw') }}</x-ui.button>
                                                @else
                                                    <span class="text-xs text-muted">{{ __('No action') }}</span>
                                                @endif
                                            </div>
                                        </td>
                                    </tr>
                                @empty
                                    <tr><td colspan="5" class="px-table-cell-x py-10 text-center text-sm text-muted">{{ __('You have no leave requests yet.') }}</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                @endif

            {{-- ====================== My Balance (employee self-service) ====================== --}}
            @elseif ($tab === 'my-balance')
                @if ($currentEmployeeId === null)
                    <x-ui.alert variant="warning">{{ __('Your user account is not linked to an employee record.') }}</x-ui.alert>
                @else
                    <div class="mb-4 grid gap-4 md:grid-cols-3">
                        <x-ui.input id="my-balance-year" type="number" wire:model.live="balanceYear" label="{{ __('Year') }}" />
                    </div>

                    @if ($myBalanceStatement && count($myBalanceStatement->rows))
                        <div class="overflow-x-auto -mx-card-inner px-card-inner mb-6">
                            <table class="min-w-full divide-y divide-border-default text-sm">
                                <thead class="bg-surface-subtle/80">
                                    <tr>
                                        <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Leave Type') }}</th>
                                        <th class="px-table-cell-x py-table-header-y text-right text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Opening') }}</th>
                                        <th class="px-table-cell-x py-table-header-y text-right text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Accrued') }}</th>
                                        <th class="px-table-cell-x py-table-header-y text-right text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Taken') }}</th>
                                        <th class="px-table-cell-x py-table-header-y text-right text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Balance') }}</th>
                                    </tr>
                                </thead>
                                <tbody class="bg-surface-card divide-y divide-border-default">
                                    @foreach ($myBalanceStatement->rows as $row)
                                        <tr wire:key="my-balance-row-{{ $row->leaveTypeId }}">
                                            <td class="px-table-cell-x py-table-cell-y">
                                                <div class="font-medium text-ink">{{ $row->leaveTypeName }}</div>
                                                <div class="text-xs text-muted font-mono">{{ $row->leaveTypeCode }}</div>
                                            </td>
                                            <td class="px-table-cell-x py-table-cell-y text-right text-ink tabular-nums">{{ number_format($row->opening, 2) }}</td>
                                            <td class="px-table-cell-x py-table-cell-y text-right text-ink tabular-nums">{{ number_format($row->accrued, 2) }}</td>
                                            <td class="px-table-cell-x py-table-cell-y text-right text-ink tabular-nums">{{ number_format($row->taken, 2) }}</td>
                                            <td class="px-table-cell-x py-table-cell-y text-right font-semibold text-ink tabular-nums">{{ number_format($row->balance, 2) }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @else
                        <x-ui.alert variant="info">{{ __('No ledger entries for :year yet.', ['year' => $balanceYear]) }}</x-ui.alert>
                    @endif

                    <x-ui.card :title="__('My Leave History')">
                        <div class="overflow-x-auto -mx-card-inner px-card-inner">
                            <table class="min-w-full divide-y divide-border-default text-sm">
                                <thead class="bg-surface-subtle/80">
                                    <tr>
                                        <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Type') }}</th>
                                        <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Period') }}</th>
                                        <th class="px-table-cell-x py-table-header-y text-right text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Qty') }}</th>
                                        <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Status') }}</th>
                                        <th class="px-table-cell-x py-table-header-y text-right text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Actions') }}</th>
                                    </tr>
                                </thead>
                                <tbody class="bg-surface-card divide-y divide-border-default">
                                    @forelse ($myRequests as $request)
                                        <tr wire:key="my-history-{{ $request->id }}">
                                            <td class="px-table-cell-x py-table-cell-y">
                                                <div class="font-medium text-ink">{{ $request->leaveType?->name }}</div>
                                                <div class="text-xs text-muted font-mono">{{ $request->leaveType?->code }}</div>
                                            </td>
                                            <td class="px-table-cell-x py-table-cell-y text-xs text-muted tabular-nums">{{ $request->starts_on?->toDateString() }} → {{ $request->ends_on?->toDateString() }}</td>
                                            <td class="px-table-cell-x py-table-cell-y text-right text-xs text-ink tabular-nums">{{ $request->quantity }} {{ $request->unit }}</td>
                                            <td class="px-table-cell-x py-table-cell-y">
                                                <x-ui.badge :variant="$this->statusVariant($request->status)">{{ __(ucfirst($request->status)) }}</x-ui.badge>
                                            </td>
                                            <td class="px-table-cell-x py-table-cell-y text-right">
                                                @if (in_array($request->status, [\App\Modules\People\Leave\Models\LeaveRequest::STATUS_APPROVED, \App\Modules\People\Leave\Models\LeaveRequest::STATUS_APPLIED], true))
                                                    <x-ui.button size="sm" variant="ghost" wire:click="withdrawOwnRequest({{ $request->id }})" wire:confirm="{{ __('Withdraw this leave request?') }}">{{ __('Withdraw') }}</x-ui.button>
                                                @endif
                                            </td>
                                        </tr>
                                    @empty
                                        <tr><td colspan="5" class="px-table-cell-x py-10 text-center text-sm text-muted">{{ __('No leave history.') }}</td></tr>
                                    @endforelse
                                </tbody>
                            </table>
                        </div>
                    </x-ui.card>
                @endif

            {{-- ====================== Types ====================== --}}
            @elseif ($tab === 'types')
                <div class="overflow-x-auto -mx-card-inner px-card-inner">
                    <table class="min-w-full divide-y divide-border-default text-sm">
                        <thead class="bg-surface-subtle/80">
                            <tr>
                                <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Type') }}</th>
                                <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Unit') }}</th>
                                <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Disposition') }}</th>
                                <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Payroll') }}</th>
                                <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Status') }}</th>
                            </tr>
                        </thead>
                        <tbody class="bg-surface-card divide-y divide-border-default">
                            @forelse ($leaveTypes as $type)
                                <tr wire:key="leave-type-{{ $type->id }}" class="hover:bg-surface-subtle/50 transition-colors">
                                    <td class="px-table-cell-x py-table-cell-y">
                                        <div class="font-medium text-ink">{{ $type->name }}</div>
                                        <div class="text-xs text-muted font-mono">{{ $type->code }}</div>
                                    </td>
                                    <td class="px-table-cell-x py-table-cell-y text-xs text-muted">{{ __(ucfirst(str_replace('_', '-', $type->default_unit))) }}</td>
                                    <td class="px-table-cell-x py-table-cell-y">
                                        <x-ui.badge :variant="$type->paid ? 'success' : 'warning'">{{ $type->paid ? __('Paid') : __('Unpaid') }}</x-ui.badge>
                                    </td>
                                    <td class="px-table-cell-x py-table-cell-y text-xs text-muted">
                                        @if ($type->interacts_with_payroll)
                                            <span class="text-muted">{{ __('Mapped in Payroll') }}</span>
                                        @else
                                            <span class="text-muted">{{ __('No payroll handoff') }}</span>
                                        @endif
                                    </td>
                                    <td class="px-table-cell-x py-table-cell-y">
                                        <x-ui.badge :variant="$type->status === 'active' ? 'success' : 'default'">{{ __(ucfirst($type->status)) }}</x-ui.badge>
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="5" class="px-table-cell-x py-10 text-center text-sm text-muted">{{ __('No leave types defined yet.') }}</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>

                @if (count($countryPacks))
                    <div class="mt-6 grid gap-4 xl:grid-cols-2">
                        @foreach ($countryPacks as $countryIso => $pack)
                            @php($manifest = $pack->manifest())
                            <x-ui.card :title="__('Country Pack: :country', ['country' => $manifest->normalizedCountryIso()])" wire:key="leave-country-pack-{{ $countryIso }}">
                                <div class="space-y-2 text-sm">
                                    <div class="font-medium text-ink">{{ $manifest->packIdentifier }}</div>
                                    <div class="text-xs text-muted">{{ __('Version') }} {{ $manifest->packVersion }} · {{ __('Data') }} {{ implode(', ', $manifest->statutoryDataVersions ?: ['-']) }}</div>
                                    @if (! empty($manifest->declaredDemographicFields))
                                        <div class="flex flex-wrap gap-2 pt-2">
                                            @foreach ($manifest->declaredDemographicFields as $field)
                                                <x-ui.badge variant="info">{{ $field }}</x-ui.badge>
                                            @endforeach
                                        </div>
                                    @endif
                                </div>
                            </x-ui.card>
                        @endforeach
                    </div>
                @endif

            {{-- ====================== Policies ====================== --}}
            @elseif ($tab === 'policies')
                @if ($canManage)
                    <div class="mb-4 flex flex-wrap items-center justify-end gap-2">
                        <x-ui.button variant="primary" wire:click="$set('showEntitlementPolicyModal', true)">
                            <x-icon name="heroicon-o-plus" class="w-4 h-4" />
                            {{ __('New Entitlement Policy') }}
                        </x-ui.button>
                        <x-ui.button variant="primary" wire:click="$set('showRequestPolicyModal', true)">
                            <x-icon name="heroicon-o-plus" class="w-4 h-4" />
                            {{ __('New Request Policy') }}
                        </x-ui.button>
                        <x-ui.button variant="ghost" wire:click="$set('showEntitlementBandModal', true)">
                            <x-icon name="heroicon-o-plus" class="w-4 h-4" />
                            {{ __('Add Band') }}
                        </x-ui.button>
                    </div>
                @endif

                <div class="grid gap-6 xl:grid-cols-2">
                    <x-ui.card :title="__('Entitlement Policies')">
                        <div class="space-y-3">
                            @forelse ($entitlementPolicies as $policy)
                                <div class="rounded-xl border border-border-default p-4" wire:key="entitlement-{{ $policy->id }}">
                                    <div class="mb-2 flex items-start justify-between gap-3">
                                        <div>
                                            <div class="font-medium text-ink">{{ $policy->name }}</div>
                                            <div class="text-xs text-muted font-mono">{{ $policy->code }} · {{ $policy->leaveType?->code }}</div>
                                        </div>
                                        <x-ui.badge variant="info">v{{ $policy->version }}</x-ui.badge>
                                    </div>
                                    <dl class="grid grid-cols-2 gap-2 text-xs">
                                        <div><dt class="text-muted">{{ __('Accrual') }}</dt><dd class="text-ink">{{ $policy->accrual_method }}</dd></div>
                                        <div><dt class="text-muted">{{ __('Rounding') }}</dt><dd class="text-ink">{{ $policy->entitlement_rounding }}</dd></div>
                                        <div><dt class="text-muted">{{ __('Carry-fwd cap') }}</dt><dd class="text-ink tabular-nums">{{ $policy->bring_forward_cap_days ?? __('unlimited') }}</dd></div>
                                        <div><dt class="text-muted">{{ __('Expiry month') }}</dt><dd class="text-ink tabular-nums">{{ $policy->bring_forward_expiry_month ?? '-' }}</dd></div>
                                        <div><dt class="text-muted">{{ __('Anchor') }}</dt><dd class="text-ink">{{ $policy->bring_forward_anchor }}</dd></div>
                                        <div><dt class="text-muted">{{ __('Effective from') }}</dt><dd class="text-ink tabular-nums">{{ $policy->effective_from?->toDateString() }}</dd></div>
                                    </dl>
                                    @if ($policy->bands->isNotEmpty())
                                        <div class="mt-3 overflow-x-auto">
                                            <table class="min-w-full text-xs">
                                                <thead class="bg-surface-subtle/80">
                                                    <tr>
                                                        <th class="px-2 py-1 text-left text-muted">{{ __('Service Years') }}</th>
                                                        <th class="px-2 py-1 text-right text-muted">{{ __('Days') }}</th>
                                                    </tr>
                                                </thead>
                                                <tbody class="divide-y divide-border-default">
                                                    @foreach ($policy->bands as $band)
                                                        <tr wire:key="band-{{ $band->id }}">
                                                            <td class="px-2 py-1 text-ink tabular-nums">{{ $band->min_years_of_service }} → {{ $band->max_years_of_service ?? '∞' }}</td>
                                                            <td class="px-2 py-1 text-right text-ink tabular-nums">{{ $band->entitlement_days }}</td>
                                                        </tr>
                                                    @endforeach
                                                </tbody>
                                            </table>
                                        </div>
                                    @endif
                                </div>
                            @empty
                                <p class="text-sm text-muted">{{ __('No entitlement policies defined yet.') }}</p>
                            @endforelse
                        </div>
                    </x-ui.card>

                    <x-ui.card :title="__('Request Policies')">
                        <div class="space-y-3">
                            @forelse ($requestPolicies as $policy)
                                <div class="rounded-xl border border-border-default p-4" wire:key="request-policy-{{ $policy->id }}">
                                    <div class="mb-2 flex items-start justify-between gap-3">
                                        <div>
                                            <div class="font-medium text-ink">{{ $policy->name }}</div>
                                            <div class="text-xs text-muted font-mono">{{ $policy->code }} · {{ $policy->leaveType?->code }}</div>
                                        </div>
                                        <x-ui.badge variant="info">v{{ $policy->version }}</x-ui.badge>
                                    </div>
                                    <div class="flex flex-wrap gap-2 text-xs">
                                        @if ($policy->allow_negative_balance)<x-ui.badge variant="warning">{{ __('Negative OK') }}</x-ui.badge>@endif
                                        @if ($policy->include_pending_as_taken)<x-ui.badge>{{ __('Encumber pending') }}</x-ui.badge>@endif
                                        @if ($policy->no_cross_month_split)<x-ui.badge>{{ __('No cross-month') }}</x-ui.badge>@endif
                                        @if ($policy->compulsory_attachment)<x-ui.badge variant="warning">{{ __('Attachment') }}</x-ui.badge>@endif
                                        @if ($policy->exclude_holiday_from_count)<x-ui.badge>{{ __('Skip holidays') }}</x-ui.badge>@endif
                                        @if ($policy->exclude_rest_day_from_count)<x-ui.badge>{{ __('Skip rest-days') }}</x-ui.badge>@endif
                                        @if ($policy->max_days_per_application)<x-ui.badge variant="info">{{ __('Max :n days', ['n' => $policy->max_days_per_application]) }}</x-ui.badge>@endif
                                    </div>
                                </div>
                            @empty
                                <p class="text-sm text-muted">{{ __('No request policies defined yet.') }}</p>
                            @endforelse
                        </div>
                    </x-ui.card>
                </div>

            {{-- ====================== Assignments ====================== --}}
            @elseif ($tab === 'assignments')
                @if ($canManage)
                    <div class="mb-4 flex justify-end">
                        <x-ui.button variant="primary" wire:click="$set('showAssignmentModal', true)">
                            <x-icon name="heroicon-o-plus" class="w-4 h-4" />
                            {{ __('New Assignment') }}
                        </x-ui.button>
                    </div>
                @endif

                <div class="overflow-x-auto -mx-card-inner px-card-inner">
                    <table class="min-w-full divide-y divide-border-default text-sm">
                        <thead class="bg-surface-subtle/80">
                            <tr>
                                <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Assignment') }}</th>
                                <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Leave Type') }}</th>
                                <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Entitlement') }}</th>
                                <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Request') }}</th>
                                <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Effective') }}</th>
                            </tr>
                        </thead>
                        <tbody class="bg-surface-card divide-y divide-border-default">
                            @forelse ($assignments as $assignment)
                                <tr wire:key="assignment-{{ $assignment->id }}">
                                    <td class="px-table-cell-x py-table-cell-y">
                                        <div class="font-medium text-ink">{{ $assignment->name }}</div>
                                        <div class="text-xs text-muted font-mono">{{ $assignment->code }}</div>
                                    </td>
                                    <td class="px-table-cell-x py-table-cell-y text-xs text-muted font-mono">{{ $assignment->leaveType?->code ?? '-' }}</td>
                                    <td class="px-table-cell-x py-table-cell-y text-xs text-muted font-mono">{{ $assignment->entitlementPolicy?->code ?? '-' }}</td>
                                    <td class="px-table-cell-x py-table-cell-y text-xs text-muted font-mono">{{ $assignment->requestPolicy?->code ?? '-' }}</td>
                                    <td class="px-table-cell-x py-table-cell-y text-xs text-muted tabular-nums">
                                        {{ $assignment->effective_from?->toDateString() }} → {{ $assignment->effective_to?->toDateString() ?? __('open') }}
                                        @if (! empty($assignment->cohort_predicate))
                                            <div class="mt-1 flex flex-wrap gap-1">
                                                @foreach ($assignment->cohort_predicate as $key => $value)
                                                    <x-ui.badge variant="info">{{ $key }}: {{ $value }}</x-ui.badge>
                                                @endforeach
                                            </div>
                                        @endif
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="5" class="px-table-cell-x py-10 text-center text-sm text-muted">{{ __('No assignments defined yet.') }}</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>

            {{-- ====================== Approvals ====================== --}}
            @elseif ($tab === 'approvals')
                <div class="grid gap-6 xl:grid-cols-[minmax(0,1fr)_minmax(24rem,0.8fr)]">
                    <div class="space-y-4">
                        <div class="overflow-x-auto -mx-card-inner px-card-inner">
                            <table class="min-w-full divide-y divide-border-default text-sm">
                                <thead class="bg-surface-subtle/80">
                                    <tr>
                                        <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Employee') }}</th>
                                        <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Type') }}</th>
                                        <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Period') }}</th>
                                        <th class="px-table-cell-x py-table-header-y text-right text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Qty') }}</th>
                                        <th class="px-table-cell-x py-table-header-y text-right text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Actions') }}</th>
                                    </tr>
                                </thead>
                                <tbody class="bg-surface-card divide-y divide-border-default">
                                    @forelse ($pendingRequests as $request)
                                        <tr wire:key="pending-request-{{ $request->id }}" class="hover:bg-surface-subtle/50 transition-colors">
                                            <td class="px-table-cell-x py-table-cell-y">
                                                <button type="button" wire:click="selectRequest({{ $request->id }})" class="text-left">
                                                    <div class="font-medium text-ink">{{ $request->employee?->displayName() ?? __('Unknown') }}</div>
                                                    <div class="text-xs text-muted font-mono">{{ $request->employee?->employee_number }}</div>
                                                </button>
                                            </td>
                                            <td class="px-table-cell-x py-table-cell-y text-xs text-muted font-mono">{{ $request->leaveType?->code }}</td>
                                            <td class="px-table-cell-x py-table-cell-y text-xs text-muted tabular-nums">
                                                {{ $request->starts_on?->toDateString() }} → {{ $request->ends_on?->toDateString() }}
                                            </td>
                                            <td class="px-table-cell-x py-table-cell-y text-right text-xs text-ink tabular-nums">{{ $request->quantity }} {{ $request->unit }}</td>
                                            <td class="px-table-cell-x py-table-cell-y">
                                                <div class="flex flex-wrap justify-end gap-2">
                                                    @if ($canApprove)
                                                        <x-ui.button size="sm" variant="primary" wire:click="approveRequest({{ $request->id }})">{{ __('Approve') }}</x-ui.button>
                                                        <x-ui.button size="sm" variant="ghost" wire:click="rejectRequest({{ $request->id }})">{{ __('Reject') }}</x-ui.button>
                                                    @else
                                                        <span class="text-xs text-muted">{{ __('View only') }}</span>
                                                    @endif
                                                </div>
                                            </td>
                                        </tr>
                                    @empty
                                        <tr><td colspan="5" class="px-table-cell-x py-10 text-center text-sm text-muted">{{ __('No leave requests are awaiting approval.') }}</td></tr>
                                    @endforelse
                                </tbody>
                            </table>
                        </div>
                        <div>{{ $pendingRequests->links() }}</div>
                    </div>

                    <div class="space-y-4">
                        @if ($selectedRequest)
                            <x-ui.card :title="__('Request Details')">
                                <div class="space-y-3 text-sm">
                                    <div class="flex items-start justify-between gap-3">
                                        <div>
                                            <div class="font-medium text-ink">{{ $selectedRequest->employee?->displayName() }}</div>
                                            <div class="text-xs text-muted font-mono">{{ $selectedRequest->leaveType?->code }}</div>
                                        </div>
                                        <x-ui.badge :variant="$this->statusVariant($selectedRequest->status)">{{ __(ucfirst($selectedRequest->status)) }}</x-ui.badge>
                                    </div>
                                    <dl class="grid grid-cols-2 gap-3 text-xs">
                                        <div><dt class="text-muted">{{ __('From') }}</dt><dd class="text-ink tabular-nums">{{ $selectedRequest->starts_on?->toDateString() }}</dd></div>
                                        <div><dt class="text-muted">{{ __('To') }}</dt><dd class="text-ink tabular-nums">{{ $selectedRequest->ends_on?->toDateString() }}</dd></div>
                                        <div><dt class="text-muted">{{ __('Quantity') }}</dt><dd class="text-ink tabular-nums">{{ $selectedRequest->quantity }} {{ $selectedRequest->unit }}</dd></div>
                                        <div><dt class="text-muted">{{ __('Submitted') }}</dt><dd class="text-ink tabular-nums">{{ $selectedRequest->submitted_at?->format('Y-m-d H:i') ?? '-' }}</dd></div>
                                    </dl>
                                    @if ($canApprove && $selectedRequest->status === \App\Modules\People\Leave\Models\LeaveRequest::STATUS_SUBMITTED)
                                        <x-ui.textarea id="approval-reason" wire:model="approvalReason" label="{{ __('Reason / Note') }}" rows="2" />
                                        <div class="flex gap-2">
                                            <x-ui.button size="sm" variant="primary" wire:click="approveRequest({{ $selectedRequest->id }})">{{ __('Approve') }}</x-ui.button>
                                            <x-ui.button size="sm" variant="ghost" wire:click="rejectRequest({{ $selectedRequest->id }})">{{ __('Reject') }}</x-ui.button>
                                        </div>
                                    @endif
                                </div>
                            </x-ui.card>

                            <x-ui.card :title="__('Days Breakdown')">
                                <div class="space-y-1 text-xs">
                                    @forelse ($selectedRequest->days as $day)
                                        <div class="flex items-center justify-between" wire:key="request-day-{{ $day->id }}">
                                            <span class="font-mono text-ink tabular-nums">{{ $day->occurs_on?->toDateString() }}</span>
                                            <span class="text-muted">{{ $day->portion }}{{ $day->hours_count ? ' · '.$day->hours_count.'h' : '' }} · {{ $day->daytype }}</span>
                                        </div>
                                    @empty
                                        <p class="text-muted">{{ __('No day-level breakdown yet.') }}</p>
                                    @endforelse
                                </div>
                            </x-ui.card>

                            <x-ui.card :title="__('Audit Trail')">
                                <div class="space-y-2 text-sm">
                                    @forelse ($selectedRequest->auditEvents as $event)
                                        <div class="rounded-lg bg-surface-subtle p-3" wire:key="leave-audit-{{ $event->id }}">
                                            <div class="flex items-center justify-between gap-3">
                                                <span class="font-medium text-ink">{{ $event->from_status }} → {{ $event->to_status }}</span>
                                                <span class="text-xs text-muted tabular-nums">{{ $event->occurred_at?->format('Y-m-d H:i') }}</span>
                                            </div>
                                            @if ($event->reason)
                                                <div class="mt-1 text-xs text-muted">{{ $event->reason }}</div>
                                            @endif
                                        </div>
                                    @empty
                                        <p class="text-sm text-muted">{{ __('No transitions recorded yet.') }}</p>
                                    @endforelse
                                </div>
                            </x-ui.card>
                        @else
                            <x-ui.card>
                                <p class="text-sm text-muted">{{ __('Select a pending request to inspect details, days breakdown, and audit trail.') }}</p>
                            </x-ui.card>
                        @endif
                    </div>
                </div>

            {{-- ====================== Calendar ====================== --}}
            @elseif ($tab === 'calendar')
                <div class="mb-4 grid gap-4 md:grid-cols-3">
                    <x-ui.input id="calendar-year" type="number" wire:model.live="calendarYear" label="{{ __('Year') }}" />
                    <x-ui.select id="calendar-state" wire:model.live="calendarState" label="{{ __('State') }}">
                        <option value="">{{ __('Federal only') }}</option>
                        <option value="KUL">{{ __('Kuala Lumpur') }}</option>
                        <option value="SGR">{{ __('Selangor') }}</option>
                    </x-ui.select>
                </div>

                <div class="grid gap-6 xl:grid-cols-2">
                    <x-ui.card :title="__('Public Holidays')">
                        <div class="space-y-1 text-sm">
                            @forelse ($publicHolidays as $holiday)
                                <div class="flex items-center justify-between gap-2 rounded-md px-2 py-1 hover:bg-surface-subtle/50" wire:key="holiday-{{ $holiday['occurs_on'] }}-{{ $holiday['name'] }}">
                                    <div class="flex items-center gap-2">
                                        <span class="font-mono text-xs text-muted tabular-nums">{{ $holiday['occurs_on'] }}</span>
                                        <span class="text-ink">{{ $holiday['name'] }}</span>
                                        @if ($holiday['substituted'])
                                            <x-ui.badge variant="info">{{ __('Substitute') }}</x-ui.badge>
                                        @endif
                                    </div>
                                    <x-ui.badge :variant="$holiday['scope'] === 'state' ? 'accent' : 'default'">{{ ucfirst($holiday['scope']) }}</x-ui.badge>
                                </div>
                            @empty
                                <p class="text-sm text-muted">{{ __('No published holidays for this year/state combination.') }}</p>
                            @endforelse
                        </div>
                    </x-ui.card>

                    <x-ui.card :title="__('Team Leave Schedule')">
                        <p class="mb-3 text-xs text-muted">{{ __('Approved, applied, and pending requests starting in :year. Overlap risk surfaces where multiple employees overlap the same dates.', ['year' => $calendarYear]) }}</p>
                        <div class="space-y-2 text-sm">
                            @forelse ($teamCalendarRequests as $request)
                                <div class="flex items-center justify-between rounded-lg border border-border-default p-3" wire:key="team-request-{{ $request->id }}">
                                    <div>
                                        <div class="font-medium text-ink">{{ $request->employee?->displayName() ?? __('Unknown') }}</div>
                                        <div class="text-xs text-muted font-mono">{{ $request->leaveType?->code }} · {{ $request->starts_on?->toDateString() }} → {{ $request->ends_on?->toDateString() }}</div>
                                    </div>
                                    <x-ui.badge :variant="$this->statusVariant($request->status)">{{ __(ucfirst($request->status)) }}</x-ui.badge>
                                </div>
                            @empty
                                <p class="text-sm text-muted">{{ __('No team leave scheduled for this year.') }}</p>
                            @endforelse
                        </div>
                    </x-ui.card>
                </div>

            {{-- ====================== Balances ====================== --}}
            @elseif ($tab === 'balances')
                <div class="mb-4 grid gap-4 md:grid-cols-3">
                    <x-ui.select id="balance-employee" wire:model.live="balanceEmployeeId" label="{{ __('Employee') }}">
                        <option value="">{{ __('Select an employee') }}</option>
                        @foreach ($employees as $employee)
                            <option value="{{ $employee->id }}">{{ $employee->employee_number }} — {{ $employee->displayName() }}</option>
                        @endforeach
                    </x-ui.select>
                    <x-ui.input id="balance-year" type="number" wire:model.live="balanceYear" label="{{ __('Year') }}" />
                </div>

                @if ($balanceStatement && count($balanceStatement->rows))
                    <div class="overflow-x-auto -mx-card-inner px-card-inner">
                        <table class="min-w-full divide-y divide-border-default text-sm">
                            <thead class="bg-surface-subtle/80">
                                <tr>
                                    <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Leave Type') }}</th>
                                    <th class="px-table-cell-x py-table-header-y text-right text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Opening') }}</th>
                                    <th class="px-table-cell-x py-table-header-y text-right text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Accrued') }}</th>
                                    <th class="px-table-cell-x py-table-header-y text-right text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Carry-Fwd') }}</th>
                                    <th class="px-table-cell-x py-table-header-y text-right text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Adjusted') }}</th>
                                    <th class="px-table-cell-x py-table-header-y text-right text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Taken') }}</th>
                                    <th class="px-table-cell-x py-table-header-y text-right text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Expired') }}</th>
                                    <th class="px-table-cell-x py-table-header-y text-right text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Encashed') }}</th>
                                    <th class="px-table-cell-x py-table-header-y text-right text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Balance') }}</th>
                                </tr>
                            </thead>
                            <tbody class="bg-surface-card divide-y divide-border-default">
                                @foreach ($balanceStatement->rows as $row)
                                    <tr wire:key="balance-row-{{ $row->leaveTypeId }}">
                                        <td class="px-table-cell-x py-table-cell-y">
                                            <div class="font-medium text-ink">{{ $row->leaveTypeName }}</div>
                                            <div class="text-xs text-muted font-mono">{{ $row->leaveTypeCode }}</div>
                                        </td>
                                        <td class="px-table-cell-x py-table-cell-y text-right text-ink tabular-nums">{{ number_format($row->opening, 2) }}</td>
                                        <td class="px-table-cell-x py-table-cell-y text-right text-ink tabular-nums">{{ number_format($row->accrued, 2) }}</td>
                                        <td class="px-table-cell-x py-table-cell-y text-right text-ink tabular-nums">{{ number_format($row->carriedForward, 2) }}</td>
                                        <td class="px-table-cell-x py-table-cell-y text-right text-ink tabular-nums">{{ number_format($row->adjusted, 2) }}</td>
                                        <td class="px-table-cell-x py-table-cell-y text-right text-ink tabular-nums">{{ number_format($row->taken, 2) }}</td>
                                        <td class="px-table-cell-x py-table-cell-y text-right text-ink tabular-nums">{{ number_format($row->expired, 2) }}</td>
                                        <td class="px-table-cell-x py-table-cell-y text-right text-ink tabular-nums">{{ number_format($row->encashed, 2) }}</td>
                                        <td class="px-table-cell-x py-table-cell-y text-right font-semibold text-ink tabular-nums">{{ number_format($row->balance, 2) }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @elseif ($balanceStatement)
                    <p class="text-sm text-muted">{{ __('No ledger entries for this employee in :year.', ['year' => $balanceYear]) }}</p>
                @else
                    <p class="text-sm text-muted">{{ __('Select an employee to view their balance statement (projected from the ledger).') }}</p>
                @endif

            {{-- ====================== Adjustments ====================== --}}
            @elseif ($tab === 'adjustments')
                @if ($canManage)
                    <div class="mb-4 flex justify-end">
                        <x-ui.button variant="primary" wire:click="$set('showAdjustmentModal', true)">
                            <x-icon name="heroicon-o-plus" class="w-4 h-4" />
                            {{ __('New Adjustment') }}
                        </x-ui.button>
                    </div>
                @endif

                <x-ui.card :title="__('Recent Manual Entries')">
                    <div class="overflow-x-auto -mx-card-inner px-card-inner">
                        <table class="min-w-full divide-y divide-border-default text-sm">
                            <thead class="bg-surface-subtle/80">
                                <tr>
                                    <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Recorded') }}</th>
                                    <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Employee') }}</th>
                                    <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Leave Type') }}</th>
                                    <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Entry') }}</th>
                                    <th class="px-table-cell-x py-table-header-y text-right text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Qty') }}</th>
                                    <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Note') }}</th>
                                </tr>
                            </thead>
                            <tbody class="bg-surface-card divide-y divide-border-default">
                                @forelse ($recentManualEntries as $entry)
                                    <tr wire:key="manual-entry-{{ $entry->id }}">
                                        <td class="px-table-cell-x py-table-cell-y text-xs text-muted tabular-nums">{{ $entry->created_at?->format('Y-m-d H:i') }}</td>
                                        <td class="px-table-cell-x py-table-cell-y text-xs text-ink">{{ $entry->employee?->displayName() ?? '-' }}</td>
                                        <td class="px-table-cell-x py-table-cell-y text-xs text-muted font-mono">{{ $entry->leaveType?->code }}</td>
                                        <td class="px-table-cell-x py-table-cell-y">
                                            <x-ui.badge>{{ $entry->entry_type }}</x-ui.badge>
                                        </td>
                                        <td class="px-table-cell-x py-table-cell-y text-right text-ink tabular-nums">{{ number_format((float) $entry->quantity, 2) }} {{ $entry->unit }}</td>
                                        <td class="px-table-cell-x py-table-cell-y text-xs text-muted">{{ $entry->note }}</td>
                                    </tr>
                                @empty
                                    <tr><td colspan="6" class="px-table-cell-x py-10 text-center text-sm text-muted">{{ __('No manual ledger entries yet.') }}</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </x-ui.card>

            {{-- ====================== Carry-Forward ====================== --}}
            @elseif ($tab === 'carry-forward')
                @if ($canManage)
                    <x-ui.card :title="__('Year-End Carry-Forward')" class="mb-6">
                        <p class="mb-4 text-xs text-muted">{{ __('Always preview before committing. Commit writes a carried_forward entry to year+1 and an expired entry to year-end for amounts above the policy cap. Operations are append-only.') }}</p>
                        <div class="grid gap-4 md:grid-cols-3">
                            <x-ui.input id="cf-from-year" type="number" wire:model="carryForwardFromYear" label="{{ __('From Year') }}" />
                            <x-ui.select id="cf-employee" wire:model="carryForwardEmployeeId" label="{{ __('Employee (optional)') }}">
                                <option value="">{{ __('All employees') }}</option>
                                @foreach ($employees as $employee)
                                    <option value="{{ $employee->id }}">{{ $employee->employee_number }} — {{ $employee->displayName() }}</option>
                                @endforeach
                            </x-ui.select>
                            <x-ui.select id="cf-leave-type" wire:model="carryForwardLeaveTypeId" label="{{ __('Leave Type (optional)') }}">
                                <option value="">{{ __('All types') }}</option>
                                @foreach ($leaveTypes as $type)
                                    <option value="{{ $type->id }}">{{ $type->code }} — {{ $type->name }}</option>
                                @endforeach
                            </x-ui.select>
                        </div>
                        <div class="mt-4 flex gap-2">
                            <x-ui.button type="button" variant="primary" wire:click="previewCarryForward">{{ __('Preview (Dry-Run)') }}</x-ui.button>
                            @if (count($carryForwardPreview))
                                <x-ui.button type="button" variant="ghost" wire:click="commitCarryForward" wire:confirm="{{ __('Commit carry-forward for the previewed pairs? This writes ledger entries.') }}">{{ __('Commit') }}</x-ui.button>
                            @endif
                        </div>
                    </x-ui.card>
                @endif

                @if (count($carryForwardPreview))
                    <x-ui.card :title="__('Preview (:n pair(s))', ['n' => count($carryForwardPreview)])">
                        <div class="overflow-x-auto -mx-card-inner px-card-inner">
                            <table class="min-w-full divide-y divide-border-default text-sm">
                                <thead class="bg-surface-subtle/80">
                                    <tr>
                                        <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Employee') }}</th>
                                        <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Leave Type') }}</th>
                                        <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Policy') }}</th>
                                        <th class="px-table-cell-x py-table-header-y text-right text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Remaining') }}</th>
                                        <th class="px-table-cell-x py-table-header-y text-right text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Cap') }}</th>
                                        <th class="px-table-cell-x py-table-header-y text-right text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Carried → :to', ['to' => $carryForwardPreview[0]['to_year'] ?? '']) }}</th>
                                        <th class="px-table-cell-x py-table-header-y text-right text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Expired') }}</th>
                                    </tr>
                                </thead>
                                <tbody class="bg-surface-card divide-y divide-border-default">
                                    @foreach ($carryForwardPreview as $row)
                                        @php($employeeLookup = $employees->firstWhere('id', $row['employee_id']))
                                        @php($leaveTypeLookup = $leaveTypes->firstWhere('id', $row['leave_type_id']))
                                        <tr wire:key="cf-preview-{{ $row['employee_id'] }}-{{ $row['leave_type_id'] }}">
                                            <td class="px-table-cell-x py-table-cell-y text-xs text-ink">{{ $employeeLookup?->displayName() ?? '#'.$row['employee_id'] }}</td>
                                            <td class="px-table-cell-x py-table-cell-y text-xs text-muted font-mono">{{ $leaveTypeLookup?->code ?? '#'.$row['leave_type_id'] }}</td>
                                            <td class="px-table-cell-x py-table-cell-y text-xs text-muted font-mono">{{ $row['policy_code'] }}</td>
                                            <td class="px-table-cell-x py-table-cell-y text-right text-ink tabular-nums">{{ number_format((float) $row['remaining'], 2) }}</td>
                                            <td class="px-table-cell-x py-table-cell-y text-right text-muted tabular-nums">{{ number_format((float) $row['cap'], 2) }}</td>
                                            <td class="px-table-cell-x py-table-cell-y text-right text-ink tabular-nums">{{ number_format((float) $row['carried'], 2) }}</td>
                                            <td class="px-table-cell-x py-table-cell-y text-right text-status-danger tabular-nums">{{ number_format((float) $row['expired'], 2) }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    </x-ui.card>
                @else
                    <p class="text-sm text-muted">{{ __('Run Preview to compute carry-forward outcomes for the selected scope.') }}</p>
                @endif
            @endif
        </x-ui.card>
    </div>

    {{-- ====================== Modals (self-service surface) ====================== --}}
    @if ($surface === 'my' && $currentEmployeeId !== null)
        <x-ui.modal wire:model="showApplyModal" class="max-w-2xl">
            <form wire:submit="applyLeave" class="p-6 space-y-4">
                <div class="flex items-start justify-between gap-3">
                    <div>
                        <h3 class="text-lg font-medium tracking-tight text-ink">{{ __('New Leave') }}</h3>
                        <p class="mt-1 text-sm text-muted">{{ __('Submit a leave request for approval.') }}</p>
                    </div>
                    <button type="button" @click="show = false" class="text-muted hover:text-ink" aria-label="{{ __('Close') }}">
                        <x-icon name="heroicon-o-x-mark" class="w-5 h-5" />
                    </button>
                </div>

                <x-ui.select id="apply-assignment" wire:model="applyAssignmentId" label="{{ __('Leave Type') }}" required :error="$errors->first('applyAssignmentId')">
                    <option value="">{{ __('Select leave type') }}</option>
                    @foreach ($myAssignments as $assignment)
                        <option value="{{ $assignment->id }}">{{ $assignment->leaveType?->name }} ({{ $assignment->code }})</option>
                    @endforeach
                </x-ui.select>

                <div class="grid gap-4 md:grid-cols-2">
                    <x-ui.input id="apply-start" type="date" wire:model="applyStartsOn" label="{{ __('Start Date') }}" required :error="$errors->first('applyStartsOn')" />
                    <x-ui.input id="apply-end" type="date" wire:model="applyEndsOn" label="{{ __('End Date') }}" required :error="$errors->first('applyEndsOn')" />
                </div>

                <div class="grid gap-4 md:grid-cols-2">
                    <x-ui.select id="apply-unit" wire:model="applyUnit" label="{{ __('Unit') }}" required :error="$errors->first('applyUnit')">
                        <option value="day">{{ __('Day') }}</option>
                        <option value="half_day">{{ __('Half Day') }}</option>
                        <option value="hour">{{ __('Hour') }}</option>
                    </x-ui.select>
                    <x-ui.input id="apply-hours" type="number" step="0.25" min="0" wire:model="applyHoursCount" label="{{ __('Hours') }}" :error="$errors->first('applyHoursCount')" />
                </div>

                <x-ui.checkbox id="apply-short-notice" wire:model="applyShortNotice" label="{{ __('Short notice') }}" />
                <x-ui.textarea id="apply-note" wire:model="applyNote" label="{{ __('Note') }}" rows="3" />

                <div class="flex justify-end gap-3 pt-2">
                    <x-ui.button type="button" variant="secondary" @click="show = false">{{ __('Cancel') }}</x-ui.button>
                    <x-ui.button type="submit" variant="primary">{{ __('Submit Request') }}</x-ui.button>
                </div>
            </form>
        </x-ui.modal>
    @endif

    {{-- ====================== Modals (settings surface) ====================== --}}
    @if ($surface === 'settings' && $canManage)
        {{-- New Leave Type --}}
        <x-ui.modal wire:model="showLeaveTypeModal" class="max-w-2xl">
            <form wire:submit="createLeaveType" class="p-6 space-y-4">
                <div class="flex items-start justify-between gap-3">
                    <h3 class="text-lg font-medium tracking-tight text-ink">{{ __('New Leave Type') }}</h3>
                    <button type="button" @click="show = false" class="text-muted hover:text-ink" aria-label="{{ __('Close') }}">
                        <x-icon name="heroicon-o-x-mark" class="w-5 h-5" />
                    </button>
                </div>
                <div class="grid gap-4 md:grid-cols-2">
                    <x-ui.input id="leave-type-code" wire:model="typeCode" label="{{ __('Code') }}" required :error="$errors->first('typeCode')" />
                    <x-ui.input id="leave-type-name" wire:model="typeName" label="{{ __('Name') }}" required :error="$errors->first('typeName')" />
                </div>
                <div class="grid gap-4 md:grid-cols-2">
                    <x-ui.select id="leave-type-unit" wire:model="typeDefaultUnit" label="{{ __('Default Unit') }}" :error="$errors->first('typeDefaultUnit')">
                        <option value="day">{{ __('Day') }}</option>
                        <option value="half_day">{{ __('Half-Day') }}</option>
                        <option value="hour">{{ __('Hour') }}</option>
                    </x-ui.select>
                </div>
                <div class="flex flex-wrap gap-4">
                    <x-ui.checkbox id="leave-type-paid" wire:model="typePaid" label="{{ __('Paid leave') }}" />
                    <x-ui.checkbox id="leave-type-payroll" wire:model="typeInteractsWithPayroll" label="{{ __('Interacts with payroll') }}" />
                    <x-ui.checkbox id="leave-type-attachment" wire:model="typeCompulsoryAttachment" label="{{ __('Attachment required') }}" />
                </div>
                <div class="flex justify-end gap-2 pt-2">
                    <x-ui.button type="button" variant="ghost" @click="show = false">{{ __('Cancel') }}</x-ui.button>
                    <x-ui.button type="submit" variant="primary">{{ __('Create Leave Type') }}</x-ui.button>
                </div>
            </form>
        </x-ui.modal>

        {{-- New Entitlement Policy --}}
        <x-ui.modal wire:model="showEntitlementPolicyModal" class="max-w-3xl">
            <form wire:submit="createEntitlementPolicy" class="p-6 space-y-4">
                <div class="flex items-start justify-between gap-3">
                    <h3 class="text-lg font-medium tracking-tight text-ink">{{ __('New Entitlement Policy') }}</h3>
                    <button type="button" @click="show = false" class="text-muted hover:text-ink" aria-label="{{ __('Close') }}">
                        <x-icon name="heroicon-o-x-mark" class="w-5 h-5" />
                    </button>
                </div>
                <x-ui.select id="entitlement-leave-type" wire:model="entitlementLeaveTypeId" label="{{ __('Leave Type') }}" required :error="$errors->first('entitlementLeaveTypeId')">
                    <option value="">{{ __('Select a leave type') }}</option>
                    @foreach ($leaveTypes as $type)
                        <option value="{{ $type->id }}">{{ $type->code }} — {{ $type->name }}</option>
                    @endforeach
                </x-ui.select>
                <div class="grid gap-4 md:grid-cols-2">
                    <x-ui.input id="entitlement-code" wire:model="entitlementCode" label="{{ __('Code') }}" required :error="$errors->first('entitlementCode')" />
                    <x-ui.input id="entitlement-name" wire:model="entitlementName" label="{{ __('Name') }}" required :error="$errors->first('entitlementName')" />
                </div>
                <div class="grid gap-4 md:grid-cols-2">
                    <x-ui.select id="entitlement-accrual" wire:model="entitlementAccrualMethod" label="{{ __('Accrual Method') }}">
                        <option value="annual_lump_no_prorate">{{ __('Annual lump (no prorate)') }}</option>
                        <option value="monthly_accrual">{{ __('Monthly accrual') }}</option>
                        <option value="earned_until_month_n">{{ __('Earned until month N') }}</option>
                        <option value="anniversary">{{ __('Anniversary') }}</option>
                    </x-ui.select>
                    <x-ui.select id="entitlement-rounding" wire:model="entitlementRounding" label="{{ __('Rounding') }}">
                        <option value="none">{{ __('None') }}</option>
                        <option value="nearest_1_day">{{ __('Nearest 1 day') }}</option>
                        <option value="nearest_half_day">{{ __('Nearest half-day') }}</option>
                    </x-ui.select>
                </div>
                <div class="grid gap-4 md:grid-cols-3">
                    <x-ui.input id="entitlement-bf-cap" wire:model="entitlementBringForwardCap" label="{{ __('Carry-Forward Cap (days)') }}" :error="$errors->first('entitlementBringForwardCap')" />
                    <x-ui.input id="entitlement-bf-expiry" type="number" wire:model="entitlementBringForwardExpiryMonth" label="{{ __('Expiry Month (1-12)') }}" :error="$errors->first('entitlementBringForwardExpiryMonth')" />
                    <x-ui.select id="entitlement-bf-anchor" wire:model="entitlementBringForwardAnchor" label="{{ __('Anchor') }}">
                        <option value="year_start">{{ __('Year start') }}</option>
                        <option value="anniversary">{{ __('Anniversary') }}</option>
                    </x-ui.select>
                </div>
                <div class="flex flex-wrap gap-4">
                    <x-ui.checkbox id="entitlement-prorate-joiners" wire:model="entitlementProrateJoiners" label="{{ __('Prorate for joiners') }}" />
                    <x-ui.checkbox id="entitlement-prorate-leavers" wire:model="entitlementProrateLeavers" label="{{ __('Prorate for leavers') }}" />
                </div>
                <x-ui.input id="entitlement-effective-from" type="date" wire:model="entitlementEffectiveFrom" label="{{ __('Effective From') }}" required :error="$errors->first('entitlementEffectiveFrom')" />
                <div class="flex justify-end gap-2 pt-2">
                    <x-ui.button type="button" variant="ghost" @click="show = false">{{ __('Cancel') }}</x-ui.button>
                    <x-ui.button type="submit" variant="primary">{{ __('Create Policy') }}</x-ui.button>
                </div>
            </form>
        </x-ui.modal>

        {{-- New Request Policy --}}
        <x-ui.modal wire:model="showRequestPolicyModal" class="max-w-3xl">
            <form wire:submit="createRequestPolicy" class="p-6 space-y-4">
                <div class="flex items-start justify-between gap-3">
                    <h3 class="text-lg font-medium tracking-tight text-ink">{{ __('New Request Policy') }}</h3>
                    <button type="button" @click="show = false" class="text-muted hover:text-ink" aria-label="{{ __('Close') }}">
                        <x-icon name="heroicon-o-x-mark" class="w-5 h-5" />
                    </button>
                </div>
                <x-ui.select id="request-leave-type" wire:model="requestLeaveTypeId" label="{{ __('Leave Type') }}" required :error="$errors->first('requestLeaveTypeId')">
                    <option value="">{{ __('Select a leave type') }}</option>
                    @foreach ($leaveTypes as $type)
                        <option value="{{ $type->id }}">{{ $type->code }} — {{ $type->name }}</option>
                    @endforeach
                </x-ui.select>
                <div class="grid gap-4 md:grid-cols-2">
                    <x-ui.input id="request-code" wire:model="requestCode" label="{{ __('Code') }}" required :error="$errors->first('requestCode')" />
                    <x-ui.input id="request-name" wire:model="requestName" label="{{ __('Name') }}" required :error="$errors->first('requestName')" />
                </div>
                <div class="grid gap-4 md:grid-cols-2">
                    <x-ui.input id="request-max-days" wire:model="requestMaxDaysPerApplication" label="{{ __('Max Days per Application') }}" :error="$errors->first('requestMaxDaysPerApplication')" />
                    <x-ui.input id="request-effective-from" type="date" wire:model="requestEffectiveFrom" label="{{ __('Effective From') }}" required :error="$errors->first('requestEffectiveFrom')" />
                </div>
                <div class="grid grid-cols-1 gap-2 sm:grid-cols-2">
                    <x-ui.checkbox id="request-allow-negative" wire:model="requestAllowNegative" label="{{ __('Allow negative balance') }}" />
                    <x-ui.checkbox id="request-include-pending" wire:model="requestIncludePending" label="{{ __('Encumber pending as taken') }}" />
                    <x-ui.checkbox id="request-multi-per-day" wire:model="requestAllowMultiplePerDay" label="{{ __('Allow multiple per day') }}" />
                    <x-ui.checkbox id="request-no-cross-month" wire:model="requestNoCrossMonth" label="{{ __('No month-boundary split') }}" />
                    <x-ui.checkbox id="request-attachment" wire:model="requestCompulsoryAttachment" label="{{ __('Attachment required') }}" />
                    <x-ui.checkbox id="request-exclude-holiday" wire:model="requestExcludeHoliday" label="{{ __('Exclude holidays') }}" />
                    <x-ui.checkbox id="request-exclude-off-day" wire:model="requestExcludeOffDay" label="{{ __('Exclude off-days') }}" />
                    <x-ui.checkbox id="request-exclude-rest-day" wire:model="requestExcludeRestDay" label="{{ __('Exclude rest-days') }}" />
                </div>
                <div class="flex justify-end gap-2 pt-2">
                    <x-ui.button type="button" variant="ghost" @click="show = false">{{ __('Cancel') }}</x-ui.button>
                    <x-ui.button type="submit" variant="primary">{{ __('Create Policy') }}</x-ui.button>
                </div>
            </form>
        </x-ui.modal>

        {{-- Add Entitlement Band --}}
        <x-ui.modal wire:model="showEntitlementBandModal" class="max-w-xl">
            <form wire:submit="addEntitlementBand" class="p-6 space-y-4">
                <div class="flex items-start justify-between gap-3">
                    <h3 class="text-lg font-medium tracking-tight text-ink">{{ __('Add Entitlement Band') }}</h3>
                    <button type="button" @click="show = false" class="text-muted hover:text-ink" aria-label="{{ __('Close') }}">
                        <x-icon name="heroicon-o-x-mark" class="w-5 h-5" />
                    </button>
                </div>
                <x-ui.select id="band-policy" wire:model="bandPolicyId" label="{{ __('Entitlement Policy') }}" required :error="$errors->first('bandPolicyId')">
                    <option value="">{{ __('Select a policy') }}</option>
                    @foreach ($entitlementPolicies as $policy)
                        <option value="{{ $policy->id }}">{{ $policy->code }} — {{ $policy->name }}</option>
                    @endforeach
                </x-ui.select>
                <div class="grid gap-4 md:grid-cols-2">
                    <x-ui.input id="band-min-years" wire:model="bandMinYears" label="{{ __('Min Years of Service') }}" required :error="$errors->first('bandMinYears')" />
                    <x-ui.input id="band-max-years" wire:model="bandMaxYears" label="{{ __('Max Years (blank = unlimited)') }}" :error="$errors->first('bandMaxYears')" />
                </div>
                <div class="grid gap-4 md:grid-cols-2">
                    <x-ui.input id="band-days" wire:model="bandDays" label="{{ __('Entitlement Days') }}" required :error="$errors->first('bandDays')" />
                    <x-ui.input id="band-bf-override" wire:model="bandCarryForwardOverride" label="{{ __('Carry-Forward Cap Override') }}" :error="$errors->first('bandCarryForwardOverride')" />
                </div>
                <div class="flex justify-end gap-2 pt-2">
                    <x-ui.button type="button" variant="ghost" @click="show = false">{{ __('Cancel') }}</x-ui.button>
                    <x-ui.button type="submit" variant="primary">{{ __('Add Band') }}</x-ui.button>
                </div>
            </form>
        </x-ui.modal>

        {{-- New Assignment --}}
        <x-ui.modal wire:model="showAssignmentModal" class="max-w-3xl">
            <form wire:submit="createAssignment" class="p-6 space-y-4">
                <div class="flex items-start justify-between gap-3">
                    <h3 class="text-lg font-medium tracking-tight text-ink">{{ __('New Leave Assignment') }}</h3>
                    <button type="button" @click="show = false" class="text-muted hover:text-ink" aria-label="{{ __('Close') }}">
                        <x-icon name="heroicon-o-x-mark" class="w-5 h-5" />
                    </button>
                </div>
                <div class="grid gap-4 md:grid-cols-2">
                    <x-ui.input id="assignment-code" wire:model="assignmentCode" label="{{ __('Code') }}" required :error="$errors->first('assignmentCode')" />
                    <x-ui.input id="assignment-name" wire:model="assignmentName" label="{{ __('Name') }}" required :error="$errors->first('assignmentName')" />
                </div>
                <div class="grid gap-4 md:grid-cols-3">
                    <x-ui.select id="assignment-leave-type" wire:model="assignmentLeaveTypeId" label="{{ __('Leave Type') }}" required :error="$errors->first('assignmentLeaveTypeId')">
                        <option value="">{{ __('Select') }}</option>
                        @foreach ($leaveTypes as $type)
                            <option value="{{ $type->id }}">{{ $type->code }}</option>
                        @endforeach
                    </x-ui.select>
                    <x-ui.select id="assignment-entitlement-policy" wire:model="assignmentEntitlementPolicyId" label="{{ __('Entitlement Policy') }}" required :error="$errors->first('assignmentEntitlementPolicyId')">
                        <option value="">{{ __('Select') }}</option>
                        @foreach ($entitlementPolicies as $policy)
                            <option value="{{ $policy->id }}">{{ $policy->code }}</option>
                        @endforeach
                    </x-ui.select>
                    <x-ui.select id="assignment-request-policy" wire:model="assignmentRequestPolicyId" label="{{ __('Request Policy') }}" required :error="$errors->first('assignmentRequestPolicyId')">
                        <option value="">{{ __('Select') }}</option>
                        @foreach ($requestPolicies as $policy)
                            <option value="{{ $policy->id }}">{{ $policy->code }}</option>
                        @endforeach
                    </x-ui.select>
                </div>
                <x-ui.input id="assignment-effective-from" type="date" wire:model="assignmentEffectiveFrom" label="{{ __('Effective From') }}" required :error="$errors->first('assignmentEffectiveFrom')" />

                <div>
                    <div class="mb-2 text-[11px] uppercase tracking-wider font-semibold text-muted">{{ __('Cohort Predicate (optional)') }}</div>
                    <p class="mb-3 text-xs text-muted">{{ __('Leave a field blank to apply the assignment regardless of that demographic.') }}</p>
                    <div class="grid gap-4 md:grid-cols-3">
                        <x-ui.select id="assignment-cohort-gender" wire:model="assignmentCohortGender" label="{{ __('Gender') }}">
                            <option value="">{{ __('Any') }}</option>
                            <option value="male">{{ __('Male') }}</option>
                            <option value="female">{{ __('Female') }}</option>
                        </x-ui.select>
                        <x-ui.select id="assignment-cohort-marital" wire:model="assignmentCohortMaritalStatus" label="{{ __('Marital Status') }}">
                            <option value="">{{ __('Any') }}</option>
                            <option value="single">{{ __('Single') }}</option>
                            <option value="married">{{ __('Married') }}</option>
                        </x-ui.select>
                        <x-ui.select id="assignment-cohort-citizenship" wire:model="assignmentCohortCitizenship" label="{{ __('Citizenship') }}">
                            <option value="">{{ __('Any') }}</option>
                            <option value="citizen">{{ __('Citizen') }}</option>
                            <option value="foreign_worker">{{ __('Foreign Worker') }}</option>
                        </x-ui.select>
                    </div>
                </div>

                <div class="flex justify-end gap-2 pt-2">
                    <x-ui.button type="button" variant="ghost" @click="show = false">{{ __('Cancel') }}</x-ui.button>
                    <x-ui.button type="submit" variant="primary">{{ __('Create Assignment') }}</x-ui.button>
                </div>
            </form>
        </x-ui.modal>

        {{-- New Ledger Adjustment --}}
        <x-ui.modal wire:model="showAdjustmentModal" class="max-w-2xl">
            <form wire:submit="recordAdjustment" class="p-6 space-y-4">
                <div class="flex items-start justify-between gap-3">
                    <h3 class="text-lg font-medium tracking-tight text-ink">{{ __('New Ledger Adjustment') }}</h3>
                    <button type="button" @click="show = false" class="text-muted hover:text-ink" aria-label="{{ __('Close') }}">
                        <x-icon name="heroicon-o-x-mark" class="w-5 h-5" />
                    </button>
                </div>
                <p class="text-xs text-muted">{{ __('Append-only. Opening for migration balances, Adjusted for corrections, Accrual for manual top-ups. Negative quantity reduces balance.') }}</p>
                <div class="grid gap-4 md:grid-cols-2">
                    <x-ui.select id="adjustment-employee" wire:model="adjustmentEmployeeId" label="{{ __('Employee') }}" required :error="$errors->first('adjustmentEmployeeId')">
                        <option value="">{{ __('Select an employee') }}</option>
                        @foreach ($employees as $employee)
                            <option value="{{ $employee->id }}">{{ $employee->employee_number }} — {{ $employee->displayName() }}</option>
                        @endforeach
                    </x-ui.select>
                    <x-ui.select id="adjustment-leave-type" wire:model="adjustmentLeaveTypeId" label="{{ __('Leave Type') }}" required :error="$errors->first('adjustmentLeaveTypeId')">
                        <option value="">{{ __('Select a leave type') }}</option>
                        @foreach ($leaveTypes as $type)
                            <option value="{{ $type->id }}">{{ $type->code }} — {{ $type->name }}</option>
                        @endforeach
                    </x-ui.select>
                </div>
                <div class="grid gap-4 md:grid-cols-4">
                    <x-ui.select id="adjustment-entry-type" wire:model="adjustmentEntryType" label="{{ __('Entry Type') }}">
                        <option value="opening">{{ __('Opening') }}</option>
                        <option value="adjusted">{{ __('Adjusted') }}</option>
                        <option value="accrual">{{ __('Accrual') }}</option>
                    </x-ui.select>
                    <x-ui.input id="adjustment-quantity" wire:model="adjustmentQuantity" label="{{ __('Quantity (+/-)') }}" required :error="$errors->first('adjustmentQuantity')" />
                    <x-ui.select id="adjustment-unit" wire:model="adjustmentUnit" label="{{ __('Unit') }}">
                        <option value="day">{{ __('Day') }}</option>
                        <option value="hour">{{ __('Hour') }}</option>
                    </x-ui.select>
                    <x-ui.input id="adjustment-year" type="number" wire:model="adjustmentYear" label="{{ __('Leave Year') }}" required :error="$errors->first('adjustmentYear')" />
                </div>
                <x-ui.textarea id="adjustment-note" wire:model="adjustmentNote" label="{{ __('Note') }}" rows="2" :error="$errors->first('adjustmentNote')" />
                <div class="flex justify-end gap-2 pt-2">
                    <x-ui.button type="button" variant="ghost" @click="show = false">{{ __('Cancel') }}</x-ui.button>
                    <x-ui.button type="submit" variant="primary">{{ __('Record Entry') }}</x-ui.button>
                </div>
            </form>
        </x-ui.modal>
    @endif
</div>
