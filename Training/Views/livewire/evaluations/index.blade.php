<div class="space-y-section-gap">
    <x-ui.page-header
        :title="__('Training evaluations')"
        :subtitle="__('Response rate and rating means per event. Comments are shown to HR only.')"
    />

    @if ($drillDown !== null)
        <x-ui.card wire:key="evaluation-drill-down">
            <div class="space-y-4 p-card-p" data-drill-down="{{ $drillDown['event_id'] }}:{{ $drillDown['criterion'] ?? 'completion' }}">
                <div class="flex flex-wrap items-baseline justify-between gap-2">
                    <h3 class="text-sm font-medium text-ink">
                        {{ $drillDown['title'] }} ·
                        @if ($drillDown['criterion'] === null)
                            {{ __(':count completed evaluations', ['count' => count($drillDown['rows'])]) }}
                        @else
                            {{ __(ucfirst(str_replace('_', ' ', $drillDown['criterion']))) }} {{ __('mean') }} {{ $drillDown['mean'] === null ? __('—') : number_format($drillDown['mean'], 2) }} {{ __('over :count evaluations', ['count' => count($drillDown['rows'])]) }}
                        @endif
                    </h3>
                    <x-ui.button type="button" variant="secondary" wire:click="closeDrillDown">{{ __('Close') }}</x-ui.button>
                </div>
                @if ($drillDown['rows'] === [])
                    <p class="text-sm text-muted">{{ __('No completed evaluation contributes to this figure.') }}</p>
                @else
                    <x-ui.table :caption="__('Evaluations contributing to :title', ['title' => $drillDown['title']])">
                        <x-slot:head>
                            <tr>
                                <x-ui.th>{{ __('Participant') }}</x-ui.th>
                                <x-ui.th>{{ __('Submitted') }}</x-ui.th>
                                <x-ui.th>{{ __('Entry') }}</x-ui.th>
                                @foreach (\App\Domains\People\Training\Services\TrainingEvaluationReader::RATINGS as $criterion)
                                    <x-ui.th>{{ __(ucfirst(str_replace('_', ' ', $criterion))) }}</x-ui.th>
                                @endforeach
                                @foreach ($drillDown['comment_columns'] as $column)
                                    <x-ui.th>{{ __(ucfirst(str_replace('_', ' ', $column))) }}</x-ui.th>
                                @endforeach
                            </tr>
                        </x-slot:head>
                        <x-slot:body>
                            @foreach ($drillDown['rows'] as $row)
                                <tr wire:key="evaluation-row-{{ $row['id'] }}" data-evaluation-row="{{ $row['id'] }}">
                                    <td class="px-table-cell-x py-table-cell-y text-sm text-ink">{{ $row['participant'] }}</td>
                                    <td class="px-table-cell-x py-table-cell-y text-sm text-ink tabular-nums">{{ $row['submitted_on'] }}</td>
                                    <td class="px-table-cell-x py-table-cell-y text-sm text-ink">{{ $row['entry_source'] }}</td>
                                    @foreach (\App\Domains\People\Training\Services\TrainingEvaluationReader::RATINGS as $criterion)
                                        <td class="px-table-cell-x py-table-cell-y text-sm text-ink tabular-nums">{{ $row[$criterion] ?? __('—') }}</td>
                                    @endforeach
                                    @foreach ($drillDown['comment_columns'] as $column)
                                        <td class="px-table-cell-x py-table-cell-y text-sm text-ink">{{ $row[$column] }}</td>
                                    @endforeach
                                </tr>
                            @endforeach
                        </x-slot:body>
                    </x-ui.table>
                @endif
            </div>
        </x-ui.card>
    @endif

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
                            <button type="button" class="underline decoration-dotted" wire:click="openCompletion({{ $event['event_id'] }})" data-drill="completion-{{ $event['event_id'] }}">{{ $event['submitted'] }}</button> / {{ $event['attended'] }} {{ __('attended') }}
                            · {{ $event['response_rate'] }}%
                        @endif
                    </span>
                </div>

                <dl class="grid grid-cols-2 gap-3 sm:grid-cols-4">
                    @foreach ($event['means'] as $criterion => $mean)
                        <div>
                            <dt class="text-xs text-muted">{{ __(ucfirst(str_replace('_', ' ', $criterion))) }} <span data-answered="{{ $event['event_id'] }}-{{ $criterion }}">({{ __(':count answered', ['count' => $event['answered'][$criterion]]) }})</span></dt>
                            <dd class="text-sm tabular-nums text-ink">
                                @if ($mean === null)
                                    {{ __('—') }}
                                @else
                                    <button type="button" class="underline decoration-dotted" wire:click="openMean({{ $event['event_id'] }}, '{{ $criterion }}')" data-drill="mean-{{ $event['event_id'] }}-{{ $criterion }}">{{ number_format($mean, 2) }}</button>
                                @endif
                            </dd>
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
</div>
