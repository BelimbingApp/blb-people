<div class="space-y-section-gap">
    <x-ui.page-header
        :title="$selected === null ? __('Team training passports') : $selected['name']"
        :subtitle="$selected === null ? __('Training records for employees in the department you head.') : __('Read-only training passport')"
    />

    @if ($selected === null)
        <x-ui.card>
            <x-ui.table container="flush" :caption="__('Team training passport summaries')">
                <x-slot name="head">
                    <tr>
                        <x-ui.th>{{ __('Employee') }}</x-ui.th>
                        <x-ui.th align="right">{{ __('Scheduled') }}</x-ui.th>
                        <x-ui.th align="right">{{ __('Attended') }}</x-ui.th>
                        <x-ui.th align="right">{{ __('Completed') }}</x-ui.th>
                        <x-ui.th align="right">{{ __('Certificates expiring in 90 days') }}</x-ui.th>
                    </tr>
                </x-slot>
                @forelse ($rows as $row)
                    <tr wire:key="team-passport-{{ $row['id'] }}" data-passport-counts="{{ $row['scheduled'] }}:{{ $row['attended'] }}:{{ $row['completed'] }}:{{ $row['expiring'] }}">
                        <td class="px-table-cell-x py-table-cell-y text-sm font-medium text-ink">
                            <a class="text-accent hover:underline" href="{{ route('people.training.team-passports', ['employeeId' => $row['id']]) }}">
                                {{ $row['name'] }}
                            </a>
                        </td>
                        <td class="px-table-cell-x py-table-cell-y text-right text-sm tabular-nums text-ink">{{ $row['scheduled'] }}</td>
                        <td class="px-table-cell-x py-table-cell-y text-right text-sm tabular-nums text-ink">{{ $row['attended'] }}</td>
                        <td class="px-table-cell-x py-table-cell-y text-right text-sm tabular-nums text-ink">{{ $row['completed'] }}</td>
                        <td class="px-table-cell-x py-table-cell-y text-right text-sm tabular-nums text-ink">{{ $row['expiring'] }}</td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="px-table-cell-x py-10 text-center text-sm text-muted">{{ __('No direct reports are available in your department.') }}</td></tr>
                @endforelse
            </x-ui.table>
        </x-ui.card>
    @else
        <div class="flex items-center justify-between gap-4">
            <a class="text-sm font-medium text-accent hover:underline" href="{{ route('people.training.team-passports') }}">{{ __('Back to team') }}</a>
            <span class="text-xs text-muted">{{ __('Generated') }} <x-ui.datetime :value="$selected['passport']->generatedAt" format="datetime" /></span>
        </div>

        <x-ui.card>
            <h2 class="mb-3 text-lg font-medium tracking-tight text-ink">{{ __('Training events') }}</h2>
            <x-ui.table container="flush" :caption="__('Training events')" :row-hover="false">
                <x-slot name="head"><tr><x-ui.th>{{ __('Training') }}</x-ui.th><x-ui.th>{{ __('Date') }}</x-ui.th><x-ui.th>{{ __('Status') }}</x-ui.th><x-ui.th>{{ __('Attendance') }}</x-ui.th></tr></x-slot>
                @forelse ($selected['passport']->events as $event)
                    <tr wire:key="team-passport-event-{{ $event->eventId }}">
                        <td class="px-table-cell-x py-table-cell-y text-sm font-medium text-ink">{{ $event->title }}</td>
                        <td class="px-table-cell-x py-table-cell-y text-sm text-ink"><x-ui.datetime :value="$event->startsAt" format="date" /></td>
                        <td class="px-table-cell-x py-table-cell-y"><x-ui.badge :variant="$event->statusVariant">{{ $event->statusLabel }}</x-ui.badge></td>
                        <td class="px-table-cell-x py-table-cell-y text-sm text-ink">{{ $event->attended ? __('Attended') : __('Attendance not recorded') }}</td>
                    </tr>
                @empty
                    <tr><td colspan="4" class="px-table-cell-x py-10 text-center text-sm text-muted">{{ __('No training events recorded yet.') }}</td></tr>
                @endforelse
            </x-ui.table>
        </x-ui.card>

        <x-ui.card>
            <h2 class="mb-3 text-lg font-medium tracking-tight text-ink">{{ __('Certificates') }}</h2>
            <div class="space-y-2">
                @forelse ($selected['passport']->certificates as $certificate)
                    <div class="flex flex-wrap items-center justify-between gap-3 border-t border-border-default py-3 first:border-t-0 first:pt-0">
                        <div><p class="text-sm font-medium text-ink">{{ $certificate->reference }}</p><p class="text-xs text-muted">{{ $certificate->eventTitle }}</p></div>
                        <x-ui.badge :variant="$certificate->statusVariant">{{ $certificate->statusLabel }}</x-ui.badge>
                    </div>
                @empty
                    <p class="text-sm text-muted">{{ __('No certificates recorded yet.') }}</p>
                @endforelse
            </div>
        </x-ui.card>
    @endif
</div>
