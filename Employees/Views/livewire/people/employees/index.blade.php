<div>
    <x-slot name="title">{{ __('Employee Workbench') }}</x-slot>

    <div class="space-y-section-gap">
        <x-ui.page-header
            :title="__('Employee Workbench')"
            :subtitle="__('Licensee-scoped employee operations, payroll readiness, and account-access follow-up.')"
        >
            <x-slot name="actions">
                <a
                    href="{{ $exportUrl }}"
                    class="inline-flex items-center gap-2 px-4 py-2 rounded-2xl text-accent hover:bg-surface-subtle transition-colors"
                >
                    <x-icon name="heroicon-o-arrow-down-tray" class="w-5 h-5" />
                    {{ __('Export CSV') }}
                </a>
            </x-slot>
        </x-ui.page-header>

        @if (session('success'))
            <x-ui.alert variant="success">{{ session('success') }}</x-ui.alert>
        @endif

        <x-ui.card>
            <div class="grid gap-4 lg:grid-cols-[2fr,1fr]">
                <div class="space-y-4">
                    <x-ui.search-input
                        wire:model.live.debounce.300ms="search"
                        placeholder="{{ __('Search by employee, company, employee number, designation, or work profile...') }}"
                    />

                    <div class="grid gap-3 md:grid-cols-2 xl:grid-cols-4">
                        <label class="space-y-1">
                            <span class="text-[11px] font-semibold uppercase tracking-wider text-muted">{{ __('Status') }}</span>
                            <select wire:model.live="status" class="w-full rounded-2xl border border-border-default bg-surface-card px-3 py-2 text-sm text-default">
                                <option value="">{{ __('All') }}</option>
                                <option value="pending">{{ __('Pending') }}</option>
                                <option value="probation">{{ __('Probation') }}</option>
                                <option value="active">{{ __('Active') }}</option>
                                <option value="inactive">{{ __('Inactive') }}</option>
                                <option value="terminated">{{ __('Terminated') }}</option>
                            </select>
                        </label>

                        <label class="space-y-1">
                            <span class="text-[11px] font-semibold uppercase tracking-wider text-muted">{{ __('Company') }}</span>
                            <select wire:model.live="companyId" class="w-full rounded-2xl border border-border-default bg-surface-card px-3 py-2 text-sm text-default">
                                <option value="">{{ __('All') }}</option>
                                @foreach($companies as $company)
                                    <option value="{{ $company->id }}">{{ $company->name }}</option>
                                @endforeach
                            </select>
                        </label>

                        <label class="space-y-1">
                            <span class="text-[11px] font-semibold uppercase tracking-wider text-muted">{{ __('Organization Unit') }}</span>
                            <select wire:model.live="organizationUnitId" class="w-full rounded-2xl border border-border-default bg-surface-card px-3 py-2 text-sm text-default">
                                <option value="">{{ __('All') }}</option>
                                @foreach($organizationUnits as $entry)
                                    <option value="{{ $entry->id }}">{{ $entry->name }}</option>
                                @endforeach
                            </select>
                        </label>

                        <label class="space-y-1">
                            <span class="text-[11px] font-semibold uppercase tracking-wider text-muted">{{ __('Cost Center') }}</span>
                            <select wire:model.live="costCenterId" class="w-full rounded-2xl border border-border-default bg-surface-card px-3 py-2 text-sm text-default">
                                <option value="">{{ __('All') }}</option>
                                @foreach($costCenters as $entry)
                                    <option value="{{ $entry->id }}">{{ $entry->name }}</option>
                                @endforeach
                            </select>
                        </label>

                        <label class="space-y-1">
                            <span class="text-[11px] font-semibold uppercase tracking-wider text-muted">{{ __('Employment Group') }}</span>
                            <select wire:model.live="employmentGroupId" class="w-full rounded-2xl border border-border-default bg-surface-card px-3 py-2 text-sm text-default">
                                <option value="">{{ __('All') }}</option>
                                @foreach($employmentGroups as $entry)
                                    <option value="{{ $entry->id }}">{{ $entry->name }}</option>
                                @endforeach
                            </select>
                        </label>

                        <label class="space-y-1">
                            <span class="text-[11px] font-semibold uppercase tracking-wider text-muted">{{ __('Job Title') }}</span>
                            <select wire:model.live="jobTitleId" class="w-full rounded-2xl border border-border-default bg-surface-card px-3 py-2 text-sm text-default">
                                <option value="">{{ __('All') }}</option>
                                @foreach($jobTitles as $entry)
                                    <option value="{{ $entry->id }}">{{ $entry->name }}</option>
                                @endforeach
                            </select>
                        </label>

                        <label class="space-y-1">
                            <span class="text-[11px] font-semibold uppercase tracking-wider text-muted">{{ __('Workforce Class') }}</span>
                            <select wire:model.live="workforceClassId" class="w-full rounded-2xl border border-border-default bg-surface-card px-3 py-2 text-sm text-default">
                                <option value="">{{ __('All') }}</option>
                                @foreach($workforceClasses as $entry)
                                    <option value="{{ $entry->id }}">{{ $entry->name }}</option>
                                @endforeach
                            </select>
                        </label>

                        <label class="space-y-1">
                            <span class="text-[11px] font-semibold uppercase tracking-wider text-muted">{{ __('Job Grade') }}</span>
                            <select wire:model.live="jobGradeId" class="w-full rounded-2xl border border-border-default bg-surface-card px-3 py-2 text-sm text-default">
                                <option value="">{{ __('All') }}</option>
                                @foreach($jobGrades as $entry)
                                    <option value="{{ $entry->id }}">{{ $entry->name }}</option>
                                @endforeach
                            </select>
                        </label>

                        <label class="space-y-1">
                            <span class="text-[11px] font-semibold uppercase tracking-wider text-muted">{{ __('Pay Basis') }}</span>
                            <select wire:model.live="payRateType" class="w-full rounded-2xl border border-border-default bg-surface-card px-3 py-2 text-sm text-default">
                                <option value="">{{ __('All') }}</option>
                                <option value="monthly">{{ __('Monthly') }}</option>
                                <option value="daily">{{ __('Daily') }}</option>
                                <option value="hourly">{{ __('Hourly') }}</option>
                                <option value="piece_rate">{{ __('Piece rate') }}</option>
                            </select>
                        </label>

                        <label class="space-y-1">
                            <span class="text-[11px] font-semibold uppercase tracking-wider text-muted">{{ __('Work Calendar') }}</span>
                            <select wire:model.live="workCalendarId" class="w-full rounded-2xl border border-border-default bg-surface-card px-3 py-2 text-sm text-default">
                                <option value="">{{ __('All') }}</option>
                                @foreach($workCalendars as $entry)
                                    <option value="{{ $entry->id }}">{{ $entry->name }}</option>
                                @endforeach
                            </select>
                        </label>

                        <label class="space-y-1">
                            <span class="text-[11px] font-semibold uppercase tracking-wider text-muted">{{ __('Account Access') }}</span>
                            <select wire:model.live="portalAccessStatus" class="w-full rounded-2xl border border-border-default bg-surface-card px-3 py-2 text-sm text-default">
                                <option value="">{{ __('All') }}</option>
                                <option value="unprovisioned">{{ __('Unprovisioned') }}</option>
                                <option value="pending">{{ __('Pending') }}</option>
                                <option value="active">{{ __('Active') }}</option>
                                <option value="revoked">{{ __('Revoked') }}</option>
                            </select>
                        </label>

                        <label class="space-y-1">
                            <span class="text-[11px] font-semibold uppercase tracking-wider text-muted">{{ __('Payroll Readiness') }}</span>
                            <select wire:model.live="readinessState" class="w-full rounded-2xl border border-border-default bg-surface-card px-3 py-2 text-sm text-default">
                                <option value="">{{ __('All') }}</option>
                                <option value="ready">{{ __('Ready') }}</option>
                                <option value="blocked">{{ __('Blocked') }}</option>
                            </select>
                        </label>

                        <label class="space-y-1">
                            <span class="text-[11px] font-semibold uppercase tracking-wider text-muted">{{ __('Readiness Blocker') }}</span>
                            <select wire:model.live="readinessBlocker" class="w-full rounded-2xl border border-border-default bg-surface-card px-3 py-2 text-sm text-default">
                                <option value="">{{ __('All') }}</option>
                                @foreach($readinessBlockers as $code => $label)
                                    <option value="{{ $code }}">{{ __($label) }}</option>
                                @endforeach
                            </select>
                        </label>
                    </div>
                </div>

                <div class="space-y-3 rounded-2xl border border-border-default bg-surface-subtle/40 p-4">
                    <div>
                        <h3 class="text-sm font-semibold text-default">{{ __('Saved Employee Views') }}</h3>
                        <p class="text-xs text-muted">{{ __('Save the current filters and sort for repeat payroll and HR follow-up.') }}</p>
                    </div>

                    <form wire:submit="saveCurrentView" class="space-y-3">
                        <label class="space-y-1 block">
                            <span class="text-[11px] font-semibold uppercase tracking-wider text-muted">{{ __('View Name') }}</span>
                            <input wire:model="savedViewName" type="text" class="w-full rounded-2xl border border-border-default bg-surface-card px-3 py-2 text-sm text-default" />
                        </label>

                        <label class="space-y-1 block">
                            <span class="text-[11px] font-semibold uppercase tracking-wider text-muted">{{ __('Visibility') }}</span>
                            <select wire:model="savedViewVisibility" class="w-full rounded-2xl border border-border-default bg-surface-card px-3 py-2 text-sm text-default">
                                <option value="private">{{ __('Private') }}</option>
                                <option value="company">{{ __('Company Shared') }}</option>
                            </select>
                        </label>

                        <div class="flex gap-2">
                            <x-ui.button type="submit" variant="primary" size="sm">{{ __('Save View') }}</x-ui.button>
                            <x-ui.button type="button" variant="ghost" size="sm" wire:click="clearFilters">{{ __('Clear Filters') }}</x-ui.button>
                        </div>
                    </form>

                    <div class="space-y-2">
                        @forelse($savedViews as $view)
                            <button
                                type="button"
                                wire:click="applySavedView({{ $view->id }})"
                                class="flex w-full items-center justify-between rounded-2xl border border-border-default bg-surface-card px-3 py-2 text-left text-sm hover:bg-surface-subtle"
                            >
                                <span class="font-medium text-default">{{ $view->name }}</span>
                                <x-ui.badge variant="{{ $view->visibility === 'company' ? 'info' : 'default' }}">
                                    {{ $view->visibility === 'company' ? __('Shared') : __('Private') }}
                                </x-ui.badge>
                            </button>
                        @empty
                            <p class="text-sm text-muted">{{ __('No saved employee views yet.') }}</p>
                        @endforelse
                    </div>
                </div>
            </div>
        </x-ui.card>

        <x-ui.card>
            <x-ui.table container="flush" :caption="__('Employees')">

                <x-slot name="head">
                        <tr>
                            <x-ui.sortable-th column="full_name" :sort-by="$sortBy" :sort-dir="$sortDir" action="sort('full_name')" :label="__('Employee')" />
                            <x-ui.sortable-th column="company_name" :sort-by="$sortBy" :sort-dir="$sortDir" action="sort('company_name')" :label="__('Company')" />
                            <x-ui.sortable-th column="organization_unit_name" :sort-by="$sortBy" :sort-dir="$sortDir" action="sort('organization_unit_name')" :label="__('Organization')" />
                            <x-ui.sortable-th column="cost_center_name" :sort-by="$sortBy" :sort-dir="$sortDir" action="sort('cost_center_name')" :label="__('Cost Center')" />
                            <x-ui.sortable-th column="job_title_name" :sort-by="$sortBy" :sort-dir="$sortDir" action="sort('job_title_name')" :label="__('Job Title')" />
                            <x-ui.sortable-th column="work_profile_pay_basis" :sort-by="$sortBy" :sort-dir="$sortDir" action="sort('work_profile_pay_basis')" :label="__('Pay Basis')" />
                            <x-ui.sortable-th column="portal_access_status" :sort-by="$sortBy" :sort-dir="$sortDir" action="sort('portal_access_status')" :label="__('Access')" />
                            <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Payroll Readiness') }}</th>
                            <x-ui.sortable-th column="status" :sort-by="$sortBy" :sort-dir="$sortDir" action="sort('status')" :label="__('Status')" />
                        </tr>
                    </x-slot>

                        @forelse($employees as $employee)
                            @php($readiness = $employee->payroll_readiness)
                            <tr wire:key="employee-{{ $employee->id }}">
                                <td class="px-table-cell-x py-table-cell-y align-top">
                                    <a href="{{ route('people.employees.show', $employee) }}" wire:navigate class="text-sm font-medium text-accent hover:underline">
                                        {{ $employee->full_name }}
                                    </a>
                                    <div class="text-xs text-muted tabular-nums">{{ $employee->employee_number }}</div>
                                    <div class="text-xs text-muted">{{ $employee->designation ?? '-' }}</div>
                                </td>
                                <td class="px-table-cell-x py-table-cell-y align-top text-sm text-muted">
                                    {{ $employee->company_name ?? $employee->company?->name ?? '-' }}
                                </td>
                                <td class="px-table-cell-x py-table-cell-y align-top">
                                    <div class="text-sm text-default">{{ $employee->organization_unit_name ?? '-' }}</div>
                                    <div class="text-xs text-muted">{{ $employee->employment_group_name ?? '-' }}</div>
                                </td>
                                <td class="px-table-cell-x py-table-cell-y align-top">
                                    <div class="text-sm text-default">{{ $employee->cost_center_name ?? '-' }}</div>
                                    @if($employee->cost_center_source_code)
                                        <div class="text-xs text-muted">{{ __('Source') }}: {{ $employee->cost_center_source_code }}</div>
                                    @endif
                                </td>
                                <td class="px-table-cell-x py-table-cell-y align-top">
                                    <div class="text-sm text-default">{{ $employee->job_title_name ?? '-' }}</div>
                                    <div class="text-xs text-muted">{{ $employee->workforce_class_name ?? '-' }}</div>
                                </td>
                                <td class="px-table-cell-x py-table-cell-y align-top text-sm text-muted">
                                    <div>{{ $employee->work_profile_pay_basis ? ucfirst(str_replace('_', ' ', $employee->work_profile_pay_basis)) : '-' }}</div>
                                    <div class="text-xs">{{ $employee->work_calendar_name ?? '-' }}</div>
                                </td>
                                <td class="px-table-cell-x py-table-cell-y align-top">
                                    <x-ui.badge :variant="$this->portalAccessVariant($employee->portal_access_status)">
                                        {{ $employee->portal_access_status ? ucfirst($employee->portal_access_status) : __('Unprovisioned') }}
                                    </x-ui.badge>
                                    <div class="mt-1 text-xs text-muted">{{ $employee->portal_access_login_identifier ?? '-' }}</div>
                                </td>
                                <td class="px-table-cell-x py-table-cell-y align-top">
                                    <x-ui.badge :variant="$this->readinessVariant($readiness['state'])">
                                        {{ ucfirst($readiness['state']) }}
                                    </x-ui.badge>
                                    <div class="mt-1 space-y-1">
                                        @forelse($readiness['blockers'] as $blocker)
                                            <div class="text-xs text-muted">{{ $blocker['label'] }}</div>
                                        @empty
                                            <div class="text-xs text-muted">{{ __('No blocking gaps detected.') }}</div>
                                        @endforelse
                                    </div>
                                </td>
                                <td class="px-table-cell-x py-table-cell-y align-top">
                                    <x-ui.badge :variant="$this->statusVariant($employee->status)">{{ ucfirst($employee->status) }}</x-ui.badge>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="9" class="px-table-cell-x py-8 text-center text-sm text-muted">{{ __('No employees found for the active workbench filters.') }}</td>
                            </tr>
                        @endforelse

            </x-ui.table>

            <div class="mt-4">
                {{ $employees->links() }}
            </div>
        </x-ui.card>
    </div>
</div>
