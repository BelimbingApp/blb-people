<div class="space-y-section-gap">
    <x-ui.page-header
        :title="__('Training calendar')"
        :subtitle="__('Open training events by month. Enrolment never changes proficiency; attendance is recorded separately.')"
    />

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
            <x-ui.button type="button" wire:click="previousMonth" variant="secondary" aria-label="{{ __('Previous month') }}">&larr;</x-ui.button>
            <h2 class="text-lg font-semibold">{{ $monthLabel }}</h2>
            <x-ui.button type="button" wire:click="nextMonth" variant="secondary" aria-label="{{ __('Next month') }}">&rarr;</x-ui.button>
            <span class="flex gap-2 ml-auto">
                <x-ui.button type="button" wire:click="showMonth" :variant="$mode === 'month' ? 'primary' : 'secondary'">{{ __('Month') }}</x-ui.button>
                <x-ui.button type="button" wire:click="showList" :variant="$mode === 'list' ? 'primary' : 'secondary'">{{ __('List') }}</x-ui.button>
            </span>
        </div>

        @if ($mode === 'month')
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
            <div class="space-y-4">
                @forelse ($events as $event)
                    <x-ui.card>
                        <div class="flex flex-wrap items-center gap-3">
                            <div>
                                <h3 class="font-semibold">{{ $event->course_title_snapshot }}</h3>
                                <p class="text-sm text-muted">
                                    {{ $event->starts_at->format('d M Y H:i') }} · {{ $event->status->label() }} ·
                                    {{ ($counts[$event->id] ?? 0) . ' of ' . $event->capacity . ' enrolled' }}
                                </p>
                            </div>
                            <span class="ml-auto">
                                @include('people::livewire.calendar.event-actions', ['event' => $event])
                            </span>
                        </div>
                    </x-ui.card>
                @empty
                    <x-ui.alert variant="info">{{ __('No open training events this month.') }}</x-ui.alert>
                @endforelse
            </div>
        @endif
    @endif
</div>
