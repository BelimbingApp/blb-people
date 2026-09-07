<div class="space-y-section-gap">
    <x-ui.page-header
        :title="__('Training evaluations')"
        :subtitle="__('Response rate and rating means per event. Comments are shown to HR only.')"
    />

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
