<div class="space-y-section-gap">
    <x-ui.page-header
        :title="__('Training evaluations')"
        :subtitle="__('Response rate and rating means per event. Comments are shown to HR only.')"
    />

    <x-ui.card>
        <div class="space-y-4 p-card-p">
            <div class="flex flex-wrap items-end justify-between gap-3">
                <div>
                    <h2 class="text-lg font-medium tracking-tight text-ink">{{ __('Overdue evaluations') }}</h2>
                    <p class="text-sm text-muted" data-overdue-count="{{ $overdue['count'] }}">
                        {{ trans_choice(':count participant past the evaluation window|:count participants past the evaluation window', $overdue['count'], ['count' => $overdue['count']]) }}
                    </p>
                </div>
                <x-ui.select wire:model.live="department" :label="__('Department')">
                    <option value="">{{ __('All departments') }}</option>
                    @foreach ($departments as $unitId => $name)
                        <option value="{{ $unitId }}">{{ $name }}</option>
                    @endforeach
                </x-ui.select>
            </div>

            @if ($overdue['rows'] !== [])
                <x-ui.table container="flush" :caption="__('Overdue evaluations')">
                    <x-slot name="head">
                        <tr>
                            <x-ui.th>{{ __('Participant') }}</x-ui.th>
                            <x-ui.th>{{ __('Department') }}</x-ui.th>
                            <x-ui.th>{{ __('Event') }}</x-ui.th>
                            <x-ui.th>{{ __('Due') }}</x-ui.th>
                            <x-ui.th>{{ __('Days overdue') }}</x-ui.th>
                        </tr>
                    </x-slot>
                    @foreach ($overdue['rows'] as $row)
                        <tr wire:key="overdue-{{ $loop->index }}">
                            <td class="px-table-cell-x py-table-cell-y text-sm text-ink">{{ $row['participant'] }}</td>
                            <td class="px-table-cell-x py-table-cell-y text-sm text-ink">{{ $row['department'] }}</td>
                            <td class="px-table-cell-x py-table-cell-y text-sm text-ink">{{ $row['event'] }}</td>
                            <td class="px-table-cell-x py-table-cell-y text-sm tabular-nums text-ink">{{ $row['due_on'] }}</td>
                            <td class="px-table-cell-x py-table-cell-y text-sm tabular-nums text-ink">{{ $row['days_overdue'] }}</td>
                        </tr>
                    @endforeach
                </x-ui.table>
            @else
                <p class="text-sm text-muted">{{ __('Nobody is past their evaluation window.') }}</p>
            @endif
        </div>
    </x-ui.card>

    @forelse ($events as $event)
        <x-ui.card wire:key="evaluation-event-{{ $event['event_id'] }}">
            <div class="space-y-4 p-card-p" data-evaluation-rate="{{ $event['response_rate'] ?? 'n/a' }}">
                <div class="flex flex-wrap items-baseline justify-between gap-2">
                    <h3 class="text-sm font-medium text-ink">{{ $event['title'] }}</h3>
                    <span class="text-xs text-muted">
                        @if ($event['response_rate'] === null)
                            {{-- Nobody attended, so there is nothing to be a percentage of. --}}
                            {{ __('No attendance recorded') }}
                        @else
                            {{ $event['submitted'] }} / {{ $event['attended'] }} {{ __('attended') }}
                            · {{ $event['response_rate'] }}%
                        @endif
                    </span>
                </div>

                <dl class="grid grid-cols-2 gap-3 sm:grid-cols-5">
                    @foreach ($event['means'] as $criterion => $mean)
                        <div>
                            <dt class="text-xs text-muted">{{ __(ucfirst(str_replace('_', ' ', $criterion))) }}</dt>
                            <dd class="text-sm tabular-nums text-ink">{{ $mean === null ? __('—') : number_format($mean, 2) }}</dd>
                        </div>
                    @endforeach
                </dl>

                @if ($event['comments'] !== [])
                    <ul class="space-y-2 border-t border-line pt-3">
                        @foreach ($event['comments'] as $comment)
                            <li class="text-sm text-ink">
                                <span class="text-muted">{{ $comment['participant'] }}:</span>
                                {{ $comment['comment'] }}
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>
        </x-ui.card>
    @empty
        <x-ui.card>
            <p class="px-table-cell-x py-10 text-center text-sm text-muted">
                {{ __('No training events are recorded for this company.') }}
            </p>
        </x-ui.card>
    @endforelse
</div>
