<div>
    <x-slot name="title">{{ __('Employee Workbench') }}</x-slot>

    <div class="space-y-section-gap">
        <x-ui.page-header
            :title="__('Employee Workbench')"
            :subtitle="__('Licensee-scoped employee operations, payroll readiness, and account-access follow-up.')"
        >
            <x-slot name="actions">
                <x-ui.link
                    kind="download"
                    href="{{ $exportUrl }}"
                >
                    {{ __('Export CSV') }}
                </x-ui.link>
            </x-slot>
        </x-ui.page-header>

        <x-ui.session-flash />

        <x-ui.card>
            <x-ui.filter-bar class="mb-3">
                <x-slot name="search">
                    <x-ui.search-input
                        id="employee-workbench-search"
                        wire:model.live.debounce.300ms="search"
                        placeholder="{{ __('Search employees…') }}"
                    />
                </x-slot>

                <div class="space-y-1">
                    <x-ui.select
                        id="employee-workbench-saved-view"
                        wire:model.live="selectedSavedViewId"
                        aria-label="{{ __('Saved view') }}"
                    >
                        <option value="">{{ __('All employees') }}</option>
                        @foreach ($savedViews as $view)
                            <option value="{{ $view->id }}">{{ $view->name }}</option>
                        @endforeach
                    </x-ui.select>
                    @if ($savedViewModified)
                        <p class="text-xs text-muted">{{ __('Saved view modified — filters no longer match the saved snapshot.') }}</p>
                    @endif
                </div>

                <x-ui.button type="button" variant="ghost" size="sm" wire:click="openSaveViewModal">
                    {{ __('Save current view…') }}
                </x-ui.button>

                <x-ui.select id="employee-workbench-status" wire:model.live="status" aria-label="{{ __('Status') }}">
                    <option value="">{{ __('All statuses') }}</option>
                    <option value="pending">{{ __('Pending') }}</option>
                    <option value="probation">{{ __('Probation') }}</option>
                    <option value="active">{{ __('Active') }}</option>
                    <option value="inactive">{{ __('Inactive') }}</option>
                    <option value="terminated">{{ __('Terminated') }}</option>
                </x-ui.select>

                <x-ui.select id="employee-workbench-readiness" wire:model.live="readinessState" aria-label="{{ __('Payroll readiness') }}">
                    <option value="">{{ __('All readiness') }}</option>
                    <option value="ready">{{ __('Ready') }}</option>
                    <option value="blocked">{{ __('Blocked') }}</option>
                </x-ui.select>

                @if ($showCompanyFilter)
                    <x-ui.select id="employee-workbench-company" wire:model.live="companyId" aria-label="{{ __('Company') }}">
                        <option value="">{{ __('All companies') }}</option>
                        @foreach ($companies as $company)
                            <option value="{{ $company->id }}">{{ $company->name }}</option>
                        @endforeach
                    </x-ui.select>
                @endif

                <x-ui.button type="button" variant="secondary" size="sm" wire:click="openFilterDrawer">
                    {{ __('Filters') }}
                    @if ($advancedFilterCount > 0)
                        <x-ui.badge variant="info" class="ml-1.5">{{ $advancedFilterCount }}</x-ui.badge>
                    @endif
                </x-ui.button>

                @if ($hasActiveFilters)
                    <x-ui.button type="button" variant="ghost" size="sm" wire:click="clearFilters">
                        {{ __('Clear all filters') }}
                    </x-ui.button>
                @endif
            </x-ui.filter-bar>

            @if ($activeAdvancedFilterChips !== [])
                <div class="mb-3 flex flex-wrap items-center gap-2">
                    @foreach ($activeAdvancedFilterChips as $chip)
                        <span class="inline-flex items-center gap-1 rounded-full border border-border-default bg-surface-subtle px-2.5 py-1 text-xs text-default">
                            <span>{{ $chip['label'] }}</span>
                            <button
                                type="button"
                                wire:click="removeAdvancedFilter('{{ $chip['property'] }}')"
                                class="rounded-full p-0.5 text-muted hover:bg-surface-card hover:text-ink focus:outline-none focus:ring-2 focus:ring-accent"
                                aria-label="{{ __('Remove filter: :label', ['label' => $chip['label']]) }}"
                            >
                                <x-icon name="heroicon-o-x-mark" class="h-3.5 w-3.5" />
                            </button>
                        </span>
                    @endforeach

                    <x-ui.button type="button" variant="ghost" size="sm" wire:click="clearAdvancedFilters">
                        {{ __('Clear advanced filters') }}
                    </x-ui.button>
                </div>
            @endif

            <x-ui.table container="flush" :caption="__('Employees')">
                <x-slot name="head">
                    <tr>
                        <x-ui.sortable-th column="full_name" :sort-by="$sortBy" :sort-dir="$sortDir" action="sort('full_name')" :label="__('Employee')" />
                        <x-ui.sortable-th column="company_name" :sort-by="$sortBy" :sort-dir="$sortDir" action="sort('company_name')" :label="__('Organisation')" />
                        <x-ui.sortable-th column="job_title_name" :sort-by="$sortBy" :sort-dir="$sortDir" action="sort('job_title_name')" :label="__('Role')" />
                        <x-ui.sortable-th column="portal_access_status" :sort-by="$sortBy" :sort-dir="$sortDir" action="sort('portal_access_status')" :label="__('Access')" />
                        <x-ui.th>{{ __('Payroll readiness') }}</x-ui.th>
                        <x-ui.sortable-th column="status" :sort-by="$sortBy" :sort-dir="$sortDir" action="sort('status')" :label="__('Status')" />
                    </tr>
                </x-slot>

                @forelse ($employees as $employee)
                    @php($readiness = $employee->payroll_readiness)
                    <tr wire:key="employee-{{ $employee->id }}">
                        <td class="px-table-cell-x py-table-cell-y align-top">
                            <a href="{{ route('people.employees.show', $employee) }}" wire:navigate class="text-sm font-medium text-accent hover:underline">
                                {{ $employee->full_name }}
                            </a>
                            <div class="text-xs text-muted tabular-nums">{{ $employee->employee_number }}</div>
                            <div class="text-xs text-muted">{{ $employee->designation ?? '-' }}</div>
                        </td>
                        <td class="px-table-cell-x py-table-cell-y align-top">
                            <div class="text-sm text-default">{{ $employee->company_name ?? $employee->company?->name ?? '-' }}</div>
                            <div class="text-xs text-muted">{{ $employee->organization_unit_name ?? '-' }}</div>
                            <div class="text-xs text-muted">{{ $employee->employment_group_name ?? '-' }}</div>
                            <div class="text-xs text-muted">{{ $employee->cost_center_name ?? '-' }}</div>
                            @if ($employee->cost_center_source_code)
                                <div class="text-xs text-muted">{{ __('Source') }}: {{ $employee->cost_center_source_code }}</div>
                            @endif
                        </td>
                        <td class="px-table-cell-x py-table-cell-y align-top">
                            <div class="text-sm text-default">{{ $employee->job_title_name ?? '-' }}</div>
                            <div class="text-xs text-muted">{{ $employee->workforce_class_name ?? '-' }}</div>
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
                                @forelse ($readiness['blockers'] as $blocker)
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
                        <td colspan="6" class="px-table-cell-x py-8 text-center text-sm text-muted">{{ __('No employees found for the active workbench filters.') }}</td>
                    </tr>
                @endforelse
            </x-ui.table>

            <div class="mt-3">
                <x-ui.pagination :paginator="$employees" id="employee-workbench-pagination" />
            </div>
        </x-ui.card>
    </div>

    <x-ui.inspector-drawer
        wire:model="filterDrawerOpen"
        close-action="closeFilterDrawer"
        labelledby="employee-workbench-filter-drawer-title"
        storage-key="blb:inspector-drawer:employee-workbench-filters"
    >
        <header class="border-b border-border-default p-card-inner">
            <div class="flex items-start justify-between gap-4">
                <div>
                    <p class="text-[11px] font-semibold uppercase tracking-wider text-muted">{{ __('Advanced filters') }}</p>
                    <h2 id="employee-workbench-filter-drawer-title" class="mt-1 text-lg font-medium tracking-tight text-ink">
                        {{ __('Filter employees') }}
                    </h2>
                    <p class="mt-1 text-sm text-muted">{{ __('Changes apply when you confirm. The table stays visible while you adjust filters.') }}</p>
                </div>
                <div class="flex shrink-0 items-center gap-1">
                    <x-ui.inspector-default-width-button />
                    <button
                        type="button"
                        wire:click="closeFilterDrawer"
                        class="rounded-2xl p-2 text-muted hover:bg-surface-subtle hover:text-ink focus:outline-none focus:ring-2 focus:ring-accent"
                        aria-label="{{ __('Close filters') }}"
                    >
                        <x-icon name="heroicon-o-x-mark" class="h-5 w-5" />
                    </button>
                </div>
            </div>
        </header>

        <div class="min-h-0 flex-1 space-y-4 overflow-y-auto p-card-inner">
            <x-ui.select id="employee-workbench-draft-organization-unit" wire:model="draftOrganizationUnitId" :label="__('Organization unit')">
                <option value="">{{ __('All') }}</option>
                @foreach ($organizationUnits as $entry)
                    <option value="{{ $entry->id }}">{{ $entry->name }}</option>
                @endforeach
            </x-ui.select>

            <x-ui.select id="employee-workbench-draft-cost-center" wire:model="draftCostCenterId" :label="__('Cost center')">
                <option value="">{{ __('All') }}</option>
                @foreach ($costCenters as $entry)
                    <option value="{{ $entry->id }}">{{ $entry->name }}</option>
                @endforeach
            </x-ui.select>

            <x-ui.select id="employee-workbench-draft-employment-group" wire:model="draftEmploymentGroupId" :label="__('Employment group')">
                <option value="">{{ __('All') }}</option>
                @foreach ($employmentGroups as $entry)
                    <option value="{{ $entry->id }}">{{ $entry->name }}</option>
                @endforeach
            </x-ui.select>

            <x-ui.select id="employee-workbench-draft-job-title" wire:model="draftJobTitleId" :label="__('Job title')">
                <option value="">{{ __('All') }}</option>
                @foreach ($jobTitles as $entry)
                    <option value="{{ $entry->id }}">{{ $entry->name }}</option>
                @endforeach
            </x-ui.select>

            <x-ui.select id="employee-workbench-draft-workforce-class" wire:model="draftWorkforceClassId" :label="__('Workforce class')">
                <option value="">{{ __('All') }}</option>
                @foreach ($workforceClasses as $entry)
                    <option value="{{ $entry->id }}">{{ $entry->name }}</option>
                @endforeach
            </x-ui.select>

            <x-ui.select id="employee-workbench-draft-job-grade" wire:model="draftJobGradeId" :label="__('Job grade')">
                <option value="">{{ __('All') }}</option>
                @foreach ($jobGrades as $entry)
                    <option value="{{ $entry->id }}">{{ $entry->name }}</option>
                @endforeach
            </x-ui.select>

            <x-ui.select id="employee-workbench-draft-work-calendar" wire:model="draftWorkCalendarId" :label="__('Work calendar')">
                <option value="">{{ __('All') }}</option>
                @foreach ($workCalendars as $entry)
                    <option value="{{ $entry->id }}">{{ $entry->name }}</option>
                @endforeach
            </x-ui.select>

            <x-ui.select id="employee-workbench-draft-pay-basis" wire:model="draftPayRateType" :label="__('Pay basis')">
                <option value="">{{ __('All') }}</option>
                <option value="monthly">{{ __('Monthly') }}</option>
                <option value="daily">{{ __('Daily') }}</option>
                <option value="hourly">{{ __('Hourly') }}</option>
                <option value="piece_rate">{{ __('Piece rate') }}</option>
            </x-ui.select>

            <x-ui.select id="employee-workbench-draft-portal-access" wire:model="draftPortalAccessStatus" :label="__('Account access')">
                <option value="">{{ __('All') }}</option>
                <option value="unprovisioned">{{ __('Unprovisioned') }}</option>
                <option value="pending">{{ __('Pending') }}</option>
                <option value="active">{{ __('Active') }}</option>
                <option value="revoked">{{ __('Revoked') }}</option>
            </x-ui.select>

            <x-ui.select id="employee-workbench-draft-readiness-blocker" wire:model="draftReadinessBlocker" :label="__('Readiness blocker')">
                <option value="">{{ __('All') }}</option>
                @foreach ($readinessBlockers as $code => $label)
                    <option value="{{ $code }}">{{ __($label) }}</option>
                @endforeach
            </x-ui.select>
        </div>

        <footer class="flex flex-wrap items-center justify-end gap-2 border-t border-border-default p-card-inner">
            <x-ui.button type="button" variant="ghost" wire:click="closeFilterDrawer">{{ __('Cancel') }}</x-ui.button>
            <x-ui.button type="button" variant="secondary" wire:click="clearAdvancedFilters">{{ __('Clear all') }}</x-ui.button>
            <x-ui.button type="button" variant="primary" wire:click="applyAdvancedFilters">{{ __('Apply filters') }}</x-ui.button>
        </footer>
    </x-ui.inspector-drawer>

    <x-ui.modal wire:model="saveViewModalOpen" labelledby="employee-workbench-save-view-title" class="max-w-lg">
        <div class="p-card-inner space-y-4">
            <div>
                <h2 id="employee-workbench-save-view-title" class="text-lg font-medium text-ink">{{ __('Save current view') }}</h2>
                <p class="mt-1 text-sm text-muted">{{ __('Preserve the active search, filters, and sort for repeat payroll and HR follow-up.') }}</p>
            </div>

            <form wire:submit="saveCurrentView" class="space-y-4">
                <x-ui.input id="employee-workbench-save-view-name" wire:model="savedViewName" :label="__('View name')" required />

                <x-ui.select id="employee-workbench-save-view-visibility" wire:model="savedViewVisibility" :label="__('Visibility')">
                    <option value="private">{{ __('Private') }}</option>
                    <option value="company">{{ __('Company shared') }}</option>
                </x-ui.select>

                <div class="flex justify-end gap-2">
                    <x-ui.button type="button" variant="ghost" wire:click="closeSaveViewModal">{{ __('Cancel') }}</x-ui.button>
                    <x-ui.button type="submit" variant="primary">{{ __('Save view') }}</x-ui.button>
                </div>
            </form>

            @if ($manageableSavedViews->isNotEmpty())
                <div class="border-t border-border-default pt-4">
                    <h3 class="text-sm font-medium text-ink">{{ __('Your saved views') }}</h3>
                    <ul class="mt-2 space-y-2">
                        @foreach ($manageableSavedViews as $view)
                            <li class="flex items-center justify-between gap-3 rounded-2xl border border-border-default px-3 py-2 text-sm">
                                <span class="text-default">{{ $view->name }}</span>
                                <x-ui.button type="button" variant="ghost" size="sm" wire:click="deleteSavedView({{ $view->id }})">
                                    {{ __('Remove') }}
                                </x-ui.button>
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endif
        </div>
    </x-ui.modal>
</div>
