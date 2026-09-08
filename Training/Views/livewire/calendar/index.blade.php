<div class="space-y-section-gap">
    <x-ui.page-header :title="__('Training schedule')" :subtitle="__('Find, join, and coordinate training in one place. Attendance and results are recorded separately.')">
        @if ($canManage)
            <x-slot name="actions">
                <x-ui.link href="{{ route('people.training.events.index', $this->scheduleEditorParameters()) }}" wire:navigate>{{ __('New schedule') }}</x-ui.link>
                <x-ui.link href="{{ route('people.training.events.index', ['company' => $companyEntityId]) }}" wire:navigate>{{ __('Manage training records') }}</x-ui.link>
            </x-slot>
        @endif
    </x-ui.page-header>

    @error('enrolment')
        <x-ui.alert variant="danger">{{ $message }}</x-ui.alert>
    @enderror

    @if ($companies === [])
        <x-ui.alert variant="info">{{ __('No authorized workforce company is available.') }}</x-ui.alert>
    @else
        @if (count($companies) > 1)
            <div class="flex flex-wrap gap-2" aria-label="{{ __('Workforce company') }}">
                @foreach ($companies as $entityId => $name)
                    <x-ui.button type="button" wire:click="selectCompany({{ $entityId }})" :variant="$companyEntityId === $entityId ? 'primary' : 'secondary'">
                        {{ $name }}
                    </x-ui.button>
                @endforeach
            </div>
        @endif

        <div class="flex flex-wrap items-center gap-2">
            @if ($view === 'calendar')
                <x-ui.button type="button" wire:click="previousMonth" variant="secondary" aria-label="{{ __('Previous month') }}">&larr;</x-ui.button>
                <h2 class="text-lg font-semibold">{{ $monthLabel }}</h2>
                <x-ui.button type="button" wire:click="nextMonth" variant="secondary" aria-label="{{ __('Next month') }}">&rarr;</x-ui.button>
            @endif
            <span class="flex gap-2 {{ $view === 'calendar' ? 'ml-auto' : '' }}">
                <x-ui.button type="button" wire:click="showCalendar" :variant="$view === 'calendar' ? 'primary' : 'secondary'">{{ __('Calendar') }}</x-ui.button>
                <x-ui.button type="button" wire:click="showTable" :variant="$view === 'table' ? 'primary' : 'secondary'">{{ __('Table') }}</x-ui.button>
            </span>
        </div>

        @if ($view === 'calendar')
            <x-ui.card>
                <table class="w-full table-fixed">
                    <thead>
                        <tr>
                            @foreach ([__('Mon'), __('Tue'), __('Wed'), __('Thu'), __('Fri'), __('Sat'), __('Sun')] as $weekday)
                                <th scope="col" class="p-2 text-sm font-semibold text-left">{{ $weekday }}</th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($weeks as $week)
                            <tr class="border-t">
                                @foreach ($week as $day)
                                    <td class="p-2 align-top {{ $day['current'] ? '' : 'opacity-50' }}">
                                        <div class="text-sm font-medium">{{ $day['date']->format('j') }}</div>
                                        @foreach ($day['events'] as $event)
                                            @include('people::livewire.calendar.event-chip', ['event' => $event])
                                        @endforeach
                                    </td>
                                @endforeach
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </x-ui.card>
        @else
            <x-ui.card>
                <x-ui.filter-bar class="mb-3">
                    <x-slot name="search"><x-ui.search-input id="training-schedule-search" wire:model.live.debounce.300ms="search" placeholder="{{ __('Search course or title…') }}" /></x-slot>
                    <x-ui.select id="training-schedule-lifecycle" wire:model.live="lifecycle" aria-label="{{ __('Lifecycle') }}">
                        <option value="">{{ __('All lifecycle states') }}</option>
                        @foreach (\App\Domains\People\Training\Enums\TrainingEventStatus::cases() as $status)
                            <option value="{{ $status->value }}">{{ $status->label() }}</option>
                        @endforeach
                    </x-ui.select>
                    <x-ui.input id="training-schedule-from" type="date" wire:model.live="from" :label="__('From')" />
                    <x-ui.input id="training-schedule-until" type="date" wire:model.live="until" :label="__('Until')" />
                    @if ($canManage)
                        <x-ui.select id="training-schedule-department" wire:model.live="department" aria-label="{{ __('Target department') }}">
                            <option value="">{{ __('All departments') }}</option>
                            @foreach ($departments as $departmentOption)
                                <option value="{{ $departmentOption->workforce_entity_id }}">{{ $departmentOption->name }}</option>
                            @endforeach
                        </x-ui.select>
                    @endif
                </x-ui.filter-bar>
                <x-ui.table container="flush" :caption="__('Training schedule')">
                    <x-slot name="head"><tr>
                        <x-ui.sortable-th column="course_title_snapshot" :sort-by="$sortBy" :sort-dir="$sortDir" action="sort('course_title_snapshot')" :label="__('Course')" />
                        <x-ui.sortable-th column="starts_at" :sort-by="$sortBy" :sort-dir="$sortDir" action="sort('starts_at')" :label="__('Starts')" />
                        <x-ui.sortable-th column="status" :sort-by="$sortBy" :sort-dir="$sortDir" action="sort('status')" :label="__('Lifecycle')" />
                        <x-ui.th>{{ __('Capacity') }}</x-ui.th><x-ui.th><span class="sr-only">{{ __('Actions') }}</span></x-ui.th>
                    </tr></x-slot>
                    @forelse ($tableEvents as $event)
                        <tr wire:key="training-schedule-event-{{ $event->id }}">
                            <td class="px-table-cell-x py-table-cell-y align-top"><div class="text-sm font-medium text-ink">{{ $event->course_title_snapshot }}</div><div class="text-xs text-muted">{{ $event->course_code_snapshot }}</div></td>
                            <td class="px-table-cell-x py-table-cell-y align-top text-sm"><x-ui.datetime :value="$event->starts_at" /></td>
                            <td class="px-table-cell-x py-table-cell-y align-top text-sm">{{ $event->status->label() }}</td>
                            <td class="px-table-cell-x py-table-cell-y align-top text-sm tabular-nums">{{ ($counts[$event->id] ?? 0) . ' / ' . $event->capacity }}</td>
                            <td class="px-table-cell-x py-table-cell-y align-top text-sm"><div class="flex flex-wrap justify-end gap-2">
                                @if ($canManage && $event->status === \App\Domains\People\Training\Enums\TrainingEventStatus::Scheduled)
                                    <x-ui.link href="{{ route('people.training.events.index', $this->scheduleEditorParameters((int) $event->id)) }}" wire:navigate>{{ __('Revise') }}</x-ui.link>
                                @endif
                                @include('people::livewire.calendar.event-actions', ['event' => $event])
                            </div></td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="px-table-cell-x py-table-cell-y"><x-ui.alert variant="info">{{ __('No training events match these filters.') }}</x-ui.alert></td></tr>
                    @endforelse
                </x-ui.table>
                <div class="mt-4">{{ $tableEvents->links() }}</div>
            </x-ui.card>
        @endif
    @endif
</div>
