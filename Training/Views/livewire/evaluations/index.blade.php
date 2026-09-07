<div class="space-y-section-gap">
    <x-ui.page-header
        :title="__('Training evaluations')"
        :subtitle="__('Response rate and rating means per event. Comments are shown to HR only.')"
    />

    @if (session('training-evaluations-status'))
        <x-ui.alert variant="success">{{ session('training-evaluations-status') }}</x-ui.alert>
    @endif

    @if ($paperCandidates !== [])
        <x-ui.card>
            <div class="space-y-4 p-card-p">
                <div>
                    <h3 class="text-sm font-medium text-ink">{{ __('Enter paper evaluation') }}</h3>
                    <p class="mt-1 max-w-prose text-sm text-muted">{{ __('Key in a completed paper form for an attended participant who has not evaluated the event yet. The record keeps the participant as subject and names you as the entering actor.') }}</p>
                </div>
                @error('paper')
                    <x-ui.alert variant="danger">{{ $message }}</x-ui.alert>
                @enderror

                <x-ui.select id="paper-participant" wire:model="paperParticipantId" :label="__('Participant')" :error="$errors->first('paperParticipantId')" required>
                    <option value="">{{ __('Choose a participant') }}</option>
                    @foreach ($paperCandidates as $candidate)
                        <option value="{{ $candidate['participant_id'] }}">{{ $candidate['label'] }}</option>
                    @endforeach
                </x-ui.select>

                <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    @foreach ([
                        'paperRelevance' => __('Relevance'),
                        'paperTrainerEffectiveness' => __('Trainer'),
                        'paperMaterialsExercises' => __('Materials'),
                        'paperPaceDuration' => __('Pace'),
                        'paperPracticalUsefulness' => __('Applicability'),
                    ] as $field => $label)
                        <x-ui.select id="{{ str($field)->kebab() }}" wire:model="{{ $field }}" :label="$label" :error="$errors->first($field)" required>
                            <option value="">{{ __('Choose 1–5') }}</option>
                            @foreach (range(1, 5) as $rating)
                                <option value="{{ $rating }}">{{ $rating }}</option>
                            @endforeach
                        </x-ui.select>
                    @endforeach
                </div>

                <x-ui.input type="text" wire:model="paperReference" :label="__('Paper form reference')" :error="$errors->first('paperReference')" maxlength="160" required />

                <div>
                    <label for="paper-comment" class="block text-sm font-medium text-ink">{{ __('Comment from the form (optional)') }}</label>
                    <textarea id="paper-comment" wire:model="paperComment" rows="3" maxlength="2000" class="mt-1 block w-full rounded border-border bg-surface text-ink shadow-sm focus:border-primary focus:ring-primary"></textarea>
                    @error('paperComment')<p class="mt-1 text-sm text-danger">{{ $message }}</p>@enderror
                </div>

                <x-ui.button wire:click="enterPaperEvaluation" wire:loading.attr="disabled" wire:target="enterPaperEvaluation">
                    <span wire:loading.remove wire:target="enterPaperEvaluation">{{ __('Enter paper evaluation') }}</span>
                    <span wire:loading wire:target="enterPaperEvaluation">{{ __('Saving…') }}</span>
                </x-ui.button>
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
                            {{ $event['submitted'] }} / {{ $event['attended'] }} {{ __('attended') }}
                            · {{ $event['response_rate'] }}%
                        @endif
                        @if ($event['paper_entries'] > 0)
                            · {{ $event['paper_entries'] }} {{ __('entered from paper by HR') }}
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
                                @if ($comment['from_paper'])
                                    <x-ui.badge variant="neutral">{{ __('Entered from paper by HR') }}</x-ui.badge>
                                @endif
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
