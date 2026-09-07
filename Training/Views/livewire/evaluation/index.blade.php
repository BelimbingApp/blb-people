<div class="space-y-section-gap">
    <x-ui.page-header
        :title="__('Training evaluations')"
        :subtitle="__('Rate attended training while the 14-day evaluation window is open. Your saved evaluation remains editable until the window closes.')"
    />

    @if ($companies === [])
        <x-ui.alert variant="info">{{ __('No company is attributed to your employee account.') }}</x-ui.alert>
    @else
        @if (count($companies) > 1)
            <div class="flex flex-wrap gap-2 text-sm">
                @foreach ($companies as $entityId => $name)
                    <x-ui.button type="button" wire:click="selectCompany({{ $entityId }})" :variant="$companyEntityId === $entityId ? 'primary' : 'secondary'">
                        {{ $name }}
                    </x-ui.button>
                @endforeach
            </div>
        @endif

        @if (session('training-evaluation-status'))
            <x-ui.alert variant="success">{{ session('training-evaluation-status') }}</x-ui.alert>
        @endif
        @error('evaluation')
            <x-ui.alert variant="danger">{{ $message }}</x-ui.alert>
        @enderror

        @if ($events === [])
            <x-ui.alert variant="info">{{ __('No attended training is ready for evaluation. It will appear here after attendance is recorded.') }}</x-ui.alert>
        @else
            <section class="space-y-4">
                <h2 class="text-lg font-semibold text-ink">{{ __('Attended training') }}</h2>
                <x-ui.table :caption="__('Attended training awaiting evaluation')">
                    <x-slot:head>
                        <tr>
                            <x-ui.th>{{ __('Training') }}</x-ui.th>
                            <x-ui.th>{{ __('Completed') }}</x-ui.th>
                            <x-ui.th>{{ __('Evaluation window') }}</x-ui.th>
                            <x-ui.th class="text-right">{{ __('Action') }}</x-ui.th>
                        </tr>
                    </x-slot:head>
                    @foreach ($events as $event)
                        <tr wire:key="training-evaluation-event-{{ $event['event_id'] }}">
                            <td class="px-4 py-3 font-medium text-ink">{{ $event['title'] }}</td>
                            <td class="px-4 py-3"><x-ui.datetime :value="$event['ends_at']" format="date" /></td>
                            <td class="px-4 py-3">
                                @if ($event['open'])
                                    <x-ui.badge variant="success">{{ __('Open until') }} <x-ui.datetime :value="$event['closes_at']" format="date" /></x-ui.badge>
                                @else
                                    <x-ui.badge variant="neutral">{{ __('Closed') }}</x-ui.badge>
                                @endif
                                @if ($event['evaluation']?->enteredFromPaper())
                                    <x-ui.badge variant="neutral">{{ __('Entered from paper by HR') }}</x-ui.badge>
                                @endif
                            </td>
                            <td class="px-4 py-3 text-right">
                                <x-ui.button type="button" wire:click="selectEvent({{ $event['event_id'] }})" :variant="$selectedEventId === $event['event_id'] ? 'primary' : 'secondary'">
                                    {{ $event['evaluation'] === null ? __('Evaluate') : ($event['evaluation']->status === \App\Domains\People\Training\Enums\TrainingEvaluationStatus::Completed ? __('Edit evaluation') : __('Continue draft')) }}
                                </x-ui.button>
                            </td>
                        </tr>
                    @endforeach
                </x-ui.table>
            </section>

            @php($selectedEvent = collect($events)->firstWhere('event_id', $selectedEventId))
            @if ($selectedEvent)
                <section class="space-y-4">
                    <div>
                        <h2 class="text-lg font-semibold text-ink">{{ $selectedEvent['title'] }}</h2>
                        <p class="mt-1 max-w-prose text-sm text-muted">{{ __('Rate each of the eight items from 1 to 5 and say how you will apply the training; the other answers are optional. Save a draft at any time and submit when complete.') }}</p>
                        @if ($selectedEvent['evaluation'])
                            <x-ui.badge :variant="$selectedEvent['evaluation']->status === \App\Domains\People\Training\Enums\TrainingEvaluationStatus::Completed ? 'success' : 'neutral'" data-evaluation-status="{{ $selectedEvent['evaluation']->status->value }}">
                                {{ $selectedEvent['evaluation']->status === \App\Domains\People\Training\Enums\TrainingEvaluationStatus::Completed ? __('Completed') : __('Draft') }}
                            </x-ui.badge>
                        @endif
                    </div>

                    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                        @foreach ([
                            'relevance' => __('Relevance'),
                            'objectivesMet' => __('Objectives met'),
                            'contentQuality' => __('Content quality'),
                            'trainerEffectiveness' => __('Trainer'),
                            'materialsExercises' => __('Materials'),
                            'paceDuration' => __('Pace'),
                            'practicalUsefulness' => __('Applicability'),
                            'overallSatisfaction' => __('Overall satisfaction'),
                        ] as $field => $label)
                            <x-ui.select id="training-evaluation-{{ str($field)->kebab() }}" wire:model="{{ $field }}" :label="$label" :error="$errors->first($field)" :disabled="! $selectedEvent['open']" required>
                                <option value="">{{ __('Choose 1–5') }}</option>
                                @foreach (range(1, 5) as $rating)
                                    <option value="{{ $rating }}">{{ $rating }}</option>
                                @endforeach
                            </x-ui.select>
                        @endforeach
                    </div>

                    <div class="grid gap-4 sm:grid-cols-2">
                        @foreach ([
                            'mostUsefulLearning' => [__('Most useful learning (optional)'), __('What was the most useful thing you learned?')],
                            'applicationCommitment' => [__('How you will apply it at work'), __('What will you do differently in your job?')],
                            'supportNeeded' => [__('Support needed (optional)'), __('What support would help you apply it?')],
                            'recommendation' => [__('Recommendation (optional)'), __('Would you recommend this training, and to whom?')],
                            'comment' => [__('Issues or improvements (optional)'), __('What worked well, or what would improve this training?')],
                        ] as $field => [$label, $placeholder])
                            <div>
                                <label for="training-evaluation-{{ str($field)->kebab() }}" class="block text-sm font-medium text-ink">{{ $label }}</label>
                                <textarea id="training-evaluation-{{ str($field)->kebab() }}" wire:model="{{ $field }}" rows="3" maxlength="2000" @disabled(! $selectedEvent['open']) class="mt-1 block w-full rounded border-border bg-surface text-ink shadow-sm focus:border-primary focus:ring-primary disabled:cursor-not-allowed disabled:bg-surface-subtle" placeholder="{{ $placeholder }}"></textarea>
                                @error($field)<p class="mt-1 text-sm text-danger">{{ $message }}</p>@enderror
                            </div>
                        @endforeach
                    </div>

                    @if ($selectedEvent['open'])
                        <div class="flex items-center gap-3">
                            <x-ui.button variant="secondary" wire:click="saveDraft({{ $selectedEvent['event_id'] }})" wire:loading.attr="disabled" wire:target="saveDraft">
                                <span wire:loading.remove wire:target="saveDraft">{{ __('Save draft') }}</span>
                                <span wire:loading wire:target="saveDraft">{{ __('Saving…') }}</span>
                            </x-ui.button>
                            <x-ui.button wire:click="submit({{ $selectedEvent['event_id'] }})" wire:loading.attr="disabled" wire:target="submit">
                                <span wire:loading.remove wire:target="submit">{{ __('Submit evaluation') }}</span>
                                <span wire:loading wire:target="submit">{{ __('Submitting…') }}</span>
                            </x-ui.button>
                            <p class="text-sm text-muted">{{ __('You can edit this response until') }} <x-ui.datetime :value="$selectedEvent['closes_at']" format="date" />.</p>
                        </div>
                    @else
                        <x-ui.alert variant="info">{{ __('This evaluation window has closed. Ask HR if a traceable correction is needed.') }}</x-ui.alert>
                    @endif
                </section>
            @endif
        @endif
    @endif
</div>
