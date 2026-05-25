@php($filterContext = $this->rosterListFilterContext($departments, $workforceClasses))
@php($includeCount = $includeCount ?? true)

<div class="flex flex-wrap items-baseline gap-x-1 gap-y-2 text-sm text-ink-soft" x-data="{ open: null }">
    @if ($includeCount)
        {{ __('Showing') }}
        <span class="font-semibold text-ink tabular-nums">{{ method_exists($employees, 'total') ? $employees->total() : $employees->count() }}</span>
        {{ __('of') }}
        <span class="font-semibold text-ink tabular-nums">{{ $companyEmployeeCount }}</span>
        {{ __('employees in') }}
    @else
        {{ __('Filtering by') }}
    @endif

    {{-- Department --}}
    <div class="relative inline-block" @click.outside="open === 'department' && (open = null)">
        <button type="button" id="roster-filter-prose-department-toggle" @click="open = (open === 'department' ? null : 'department')" :aria-expanded="open === 'department'" aria-controls="roster-filter-prose-department-panel" class="font-medium text-ink underline decoration-dashed decoration-border-default underline-offset-4 hover:decoration-accent focus:outline-none focus:ring-2 focus:ring-accent focus:ring-offset-1 focus:rounded-sm">{{ $filterContext['departmentLabel'] }}</button>
        <section id="roster-filter-prose-department-panel" x-show="open === 'department'" x-cloak x-transition.origin.top.left class="absolute left-0 z-20 mt-2 w-64 rounded-2xl border border-border-default bg-surface-card p-3 shadow-lg" aria-labelledby="roster-filter-prose-department-toggle">
            <x-ui.select id="roster-filter-prose-department" wire:model.live="rosterDepartmentId" label="{{ __('Department') }}">
                <option value="">{{ __('All departments') }}</option>
                @foreach ($departments as $department)
                    <option value="{{ $department->id }}">{{ $department->name }}</option>
                @endforeach
            </x-ui.select>
        </section>
    </div>

    <span>,</span>

    {{-- Workforce class --}}
    <div class="relative inline-block" @click.outside="open === 'workforce' && (open = null)">
        <button type="button" id="roster-filter-prose-workforce-toggle" @click="open = (open === 'workforce' ? null : 'workforce')" :aria-expanded="open === 'workforce'" aria-controls="roster-filter-prose-workforce-panel" class="font-medium text-ink underline decoration-dashed decoration-border-default underline-offset-4 hover:decoration-accent focus:outline-none focus:ring-2 focus:ring-accent focus:ring-offset-1 focus:rounded-sm">{{ $filterContext['workforceClassLabel'] }}</button>
        <section id="roster-filter-prose-workforce-panel" x-show="open === 'workforce'" x-cloak x-transition.origin.top.left class="absolute left-0 z-20 mt-2 w-64 rounded-2xl border border-border-default bg-surface-card p-3 shadow-lg" aria-labelledby="roster-filter-prose-workforce-toggle">
            <x-ui.select id="roster-filter-prose-workforce" wire:model.live="rosterWorkforceClassId" label="{{ __('Workforce class') }}">
                <option value="">{{ __('All workforce classes') }}</option>
                @foreach ($workforceClasses as $entry)
                    <option value="{{ $entry->id }}">{{ $entry->name }}</option>
                @endforeach
            </x-ui.select>
        </section>
    </div>

    <span>,</span>

    {{-- Status --}}
    <div class="relative inline-block" @click.outside="open === 'status' && (open = null)">
        <button type="button" id="roster-filter-prose-status-toggle" @click="open = (open === 'status' ? null : 'status')" :aria-expanded="open === 'status'" aria-controls="roster-filter-prose-status-panel" class="font-medium text-ink underline decoration-dashed decoration-border-default underline-offset-4 hover:decoration-accent focus:outline-none focus:ring-2 focus:ring-accent focus:ring-offset-1 focus:rounded-sm">{{ $filterContext['statusLabel'] }}</button>
        <section id="roster-filter-prose-status-panel" x-show="open === 'status'" x-cloak x-transition.origin.top.left class="absolute left-0 z-20 mt-2 w-56 rounded-2xl border border-border-default bg-surface-card p-3 shadow-lg" aria-labelledby="roster-filter-prose-status-toggle">
            <x-ui.select id="roster-filter-prose-status" wire:model.live="rosterEmployeeStatus" label="{{ __('Status') }}">
                <option value="">{{ __('Any status') }}</option>
                <option value="active">{{ __('Active') }}</option>
                <option value="probation">{{ __('Probation') }}</option>
                <option value="pending">{{ __('Pending') }}</option>
                <option value="inactive">{{ __('Inactive') }}</option>
                <option value="terminated">{{ __('Terminated') }}</option>
            </x-ui.select>
        </section>
    </div>

    <span>.</span>

    {{-- More filters disclosure --}}
    <div class="relative inline-block" x-data="{ moreOpen: false }" @click.outside="moreOpen = false">
        <button type="button" @click="moreOpen = ! moreOpen" :aria-expanded="moreOpen" class="text-xs font-medium text-muted hover:text-ink focus:outline-none focus:ring-2 focus:ring-accent focus:ring-offset-1 focus:rounded-sm">{{ __('More filters') }}</button>
        <section x-show="moreOpen" x-cloak x-transition.origin.top.left class="absolute left-0 z-20 mt-2 grid w-80 gap-3 rounded-2xl border border-border-default bg-surface-card p-4 shadow-lg" aria-label="{{ __('More roster filters') }}">
            <div>
                <span class="text-[11px] font-semibold uppercase tracking-wider text-muted">{{ __('Search') }}</span>
                <input id="roster-filter-prose-search" wire:model.live.debounce.300ms="rosterSearch" type="search" class="mt-1 w-full rounded-2xl border border-border-default bg-surface-card px-3 py-2 text-sm text-default" placeholder="{{ __('Name, number, designation') }}" />
            </div>
            <x-ui.select id="roster-filter-prose-pay-basis" wire:model.live="rosterPayRateType" label="{{ __('Pay basis') }}">
                <option value="">{{ __('All') }}</option>
                <option value="monthly">{{ __('Monthly') }}</option>
                <option value="daily">{{ __('Daily') }}</option>
                <option value="hourly">{{ __('Hourly') }}</option>
                <option value="piece_rate">{{ __('Piece rate') }}</option>
            </x-ui.select>
            <x-ui.select id="roster-filter-prose-supervisor" wire:model.live="rosterSupervisorId" label="{{ __('Supervisor') }}">
                <option value="">{{ __('All') }}</option>
                @foreach ($supervisors as $supervisor)
                    <option value="{{ $supervisor->id }}">{{ $supervisor->full_name }} — {{ $supervisor->employee_number }}</option>
                @endforeach
            </x-ui.select>
            <x-ui.select id="roster-filter-prose-organization" wire:model.live="rosterOrganizationUnitId" label="{{ __('Organization') }}">
                <option value="">{{ __('All') }}</option>
                @foreach ($organizationUnits as $entry)
                    <option value="{{ $entry->id }}">{{ $entry->name }}</option>
                @endforeach
            </x-ui.select>
            <x-ui.select id="roster-filter-prose-cost-center" wire:model.live="rosterCostCenterId" label="{{ __('Cost center') }}">
                <option value="">{{ __('All') }}</option>
                @foreach ($costCenters as $entry)
                    <option value="{{ $entry->id }}">{{ $entry->name }}</option>
                @endforeach
            </x-ui.select>
            <x-ui.select id="roster-filter-prose-employment-group" wire:model.live="rosterEmploymentGroupId" label="{{ __('Employment group') }}">
                <option value="">{{ __('All') }}</option>
                @foreach ($employmentGroups as $entry)
                    <option value="{{ $entry->id }}">{{ $entry->name }}</option>
                @endforeach
            </x-ui.select>
            <x-ui.select id="roster-filter-prose-work-calendar" wire:model.live="rosterWorkCalendarId" label="{{ __('Work calendar') }}">
                <option value="">{{ __('All') }}</option>
                @foreach ($workCalendars as $entry)
                    <option value="{{ $entry->id }}">{{ $entry->name }}</option>
                @endforeach
            </x-ui.select>
        </section>
    </div>

    @if ($filterContext['hasActiveFilters'])
        <button type="button" wire:click="clearRosterFilters" class="ml-2 text-xs font-medium text-accent hover:underline focus:outline-none focus:ring-2 focus:ring-accent focus:ring-offset-1 focus:rounded-sm">{{ __('Clear filters') }}</button>
    @endif
</div>
