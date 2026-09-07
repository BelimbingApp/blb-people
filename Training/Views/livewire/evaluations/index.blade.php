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

                @if ($event['flagged'] !== [])
                    <div class="space-y-2 border-t border-line pt-3">
                        <h4 class="text-xs font-medium text-muted">{{ __('HR follow-ups on support requests and concerns') }}</h4>
                        <ul class="space-y-3">
                            @foreach ($event['flagged'] as $flag)
                                <li class="space-y-1 text-sm text-ink" wire:key="followup-{{ $flag['evaluation_id'] }}">
                                    <span class="text-muted">{{ $flag['participant'] }}:</span>
                                    @if ($flag['support'] !== null)
                                        <span>{{ __('Support request follow-up: :status', ['status' => str_replace('_', ' ', $flag['support']['status'])]) }}</span>
                                    @endif
                                    @if ($flag['provider'] !== null)
                                        <span>{{ __('Provider concern follow-up: :status', ['status' => str_replace('_', ' ', $flag['provider']['status'])]) }}</span>
                                    @endif
                                    @if ($canManageFollowups)
                                        <div class="flex flex-wrap items-center gap-2">
                                            @if ($flag['support'] === null || $flag['support']['status'] === 'closed')
                                                <x-ui.button size="sm" wire:click="openFollowup({{ $flag['evaluation_id'] }}, 'support_request')">{{ __('Open support follow-up') }}</x-ui.button>
                                            @else
                                                <x-ui.textarea
                                                    :label="__('Action note')"
                                                    wire:model="followupNotes.{{ $flag['support']['id'] }}"
                                                    rows="2"
                                                />
                                                <x-ui.button size="sm" wire:click="progressFollowup({{ $flag['support']['id'] }})">{{ __('Record progress') }}</x-ui.button>
                                                <x-ui.button size="sm" wire:click="closeFollowup({{ $flag['support']['id'] }})">{{ __('Close') }}</x-ui.button>
                                            @endif
                                            @if ($flag['provider'] === null || $flag['provider']['status'] === 'closed')
                                                <x-ui.button size="sm" wire:click="openFollowup({{ $flag['evaluation_id'] }}, 'provider_concern')">{{ __('Open provider concern') }}</x-ui.button>
                                            @else
                                                <x-ui.textarea
                                                    :label="__('Action note')"
                                                    wire:model="followupNotes.{{ $flag['provider']['id'] }}"
                                                    rows="2"
                                                />
                                                <x-ui.button size="sm" wire:click="progressFollowup({{ $flag['provider']['id'] }})">{{ __('Record progress') }}</x-ui.button>
                                                <x-ui.button size="sm" wire:click="closeFollowup({{ $flag['provider']['id'] }})">{{ __('Close') }}</x-ui.button>
                                            @endif
                                        </div>
                                        @error('followup')
                                            <p class="text-sm text-danger">{{ $message }}</p>
                                        @enderror
                                    @endif
                                </li>
                            @endforeach
                        </ul>
                    </div>
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
