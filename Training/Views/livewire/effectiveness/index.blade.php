<div class="space-y-section-gap">
    <x-ui.page-header
        :title="__('Training effectiveness')"
        :subtitle="__('Thirty, sixty and ninety days after your team attended a course, say whether it is being used.')"
    />

    @include('people::livewire.effectiveness.partials.tabs')

    @if (session('training-effectiveness-status'))
        <x-ui.alert variant="success">{{ session('training-effectiveness-status') }}</x-ui.alert>
    @endif
    @error('effectiveness')
        <x-ui.alert variant="danger">{{ $message }}</x-ui.alert>
    @enderror
    @error('followUp')
        <x-ui.alert variant="danger">{{ $message }}</x-ui.alert>
    @enderror

    @if ($rows === [])
        {{-- Two different answers, and the second one is the one that tells a
             reader they are looking at somebody else's queue (#436). --}}
        @if ($companyHasOpenCheckpoints)
            <x-ui.alert variant="info">
                {{ __('No effectiveness question is open for your department. Questions are open for other departments; each one is answered by the head it was assigned to.') }}
            </x-ui.alert>
        @else
            <x-ui.alert variant="info">
                {{ __('No attended training has reached a checkpoint yet, so there is nothing to answer in any department.') }}
            </x-ui.alert>
        @endif
    @else
        @foreach ($rows as $row)
            <x-ui.card wire:key="checkpoint-{{ $row->participantId }}">
                <div class="space-y-4">
                    <div class="flex flex-wrap items-center gap-2">
                        <span class="text-ink font-semibold">{{ $names[$row->employeeEntityId] ?? __('Unknown employee') }}</span>
                        <span class="text-muted">{{ $courses[$row->eventId] ?? __('Unknown course') }}</span>
                        <x-ui.badge variant="neutral">{{ $row->checkpoint->label() }}</x-ui.badge>
                        @if ($row->answered)
                            {{-- Answered is still editable until the next checkpoint opens. --}}
                            <x-ui.badge variant="success">{{ __('Answered') }}</x-ui.badge>
                        @endif
                    </div>

                    <label class="block text-sm text-muted" for="rating-{{ $row->participantId }}">
                        {{ __('Has the training been applied? 1 (not at all) to 5 (fully)') }}
                    </label>
                    <x-ui.input
                        id="rating-{{ $row->participantId }}"
                        type="number"
                        min="1"
                        max="5"
                        wire:model="rating.{{ $row->participantId }}"
                    />
                    <x-ui.textarea
                        id="comment-{{ $row->participantId }}"
                        wire:model="comment.{{ $row->participantId }}"
                        rows="3"
                        :label="__('One comment: what you have seen, or not seen')"
                    />
                    <x-ui.button type="button" wire:click="save({{ $row->participantId }})">
                        {{ $row->answered ? __('Update answer') : __('Record answer') }}
                    </x-ui.button>
                </div>
            </x-ui.card>
        @endforeach
    @endif

    <section class="space-y-4">
        <h2 class="text-lg font-semibold text-ink">{{ __('Reviews still owing a follow-up') }}</h2>
        <p class="text-sm text-muted">
            {{ __('A review that did not find the training effective is not finished until somebody owns the next step, with a due date and a reassessment.') }}
        </p>

        @if ($followUpReviews === [])
            <x-ui.alert variant="info">{{ __('No review of yours is waiting for a follow-up action.') }}</x-ui.alert>
        @else
            @foreach ($followUpReviews as $review)
                <x-ui.card wire:key="follow-up-{{ $review->id }}">
                    <div class="space-y-4">
                        <div class="flex flex-wrap items-center gap-2">
                            <x-ui.badge variant="neutral">{{ $review->stage->label() }}</x-ui.badge>
                            <x-ui.badge variant="warning">{{ $review->outcome->label() }}</x-ui.badge>
                            <span class="text-muted">{{ __('Due :date', ['date' => $review->due_on->toDateString()]) }}</span>
                        </div>

                        @if ($review->further_action)
                            <p class="text-sm text-muted">{{ $review->further_action }}</p>
                        @endif

                        <div class="grid gap-4 sm:grid-cols-2">
                            <x-ui.select
                                id="follow-up-type-{{ $review->id }}"
                                :label="__('Kind of action')"
                                wire:model="followUp.{{ $review->id }}.type"
                            >
                                @foreach ($followUpTypes as $type)
                                    <option value="{{ $type->value }}">{{ $type->label() }}</option>
                                @endforeach
                            </x-ui.select>

                            <x-ui.select
                                id="follow-up-criticality-{{ $review->id }}"
                                :label="__('Criticality of the requirement')"
                                wire:model="followUp.{{ $review->id }}.criticality"
                            >
                                @foreach ($followUpCriticalities as $criticality)
                                    <option value="{{ $criticality->value }}">{{ ucfirst($criticality->value) }}</option>
                                @endforeach
                            </x-ui.select>

                            @if (count($followUpSkills[$review->id] ?? []) > 1)
                                <x-ui.select
                                    id="follow-up-skill-{{ $review->id }}"
                                    :label="__('Skill this follow-up addresses')"
                                    wire:model="followUp.{{ $review->id }}.skill"
                                >
                                    <option value="">{{ __('Choose a skill the course covered') }}</option>
                                    @foreach ($followUpSkills[$review->id] as $skillId => $skillName)
                                        <option value="{{ $skillId }}">{{ $skillName }}</option>
                                    @endforeach
                                </x-ui.select>
                            @endif

                            <x-ui.select
                                id="follow-up-owner-{{ $review->id }}"
                                :label="__('Who owns the action')"
                                wire:model="followUp.{{ $review->id }}.owner"
                            >
                                <option value="">{{ __('Choose an owner') }}</option>
                                @foreach ($followUpEmployees as $employeeId => $employeeName)
                                    <option value="{{ $employeeId }}">{{ $employeeName }}</option>
                                @endforeach
                            </x-ui.select>

                            <x-ui.select
                                id="follow-up-coordinator-{{ $review->id }}"
                                :label="__('HR coordinator')"
                                wire:model="followUp.{{ $review->id }}.coordinator"
                            >
                                <option value="">{{ __('Choose a coordinator') }}</option>
                                @foreach ($followUpEmployees as $employeeId => $employeeName)
                                    <option value="{{ $employeeId }}">{{ $employeeName }}</option>
                                @endforeach
                            </x-ui.select>

                            <x-ui.select
                                id="follow-up-trainer-{{ $review->id }}"
                                :label="__('Trainer or coach, when the kind of action needs one')"
                                wire:model="followUp.{{ $review->id }}.trainer"
                            >
                                <option value="">{{ __('None') }}</option>
                                @foreach ($followUpEmployees as $employeeId => $employeeName)
                                    <option value="{{ $employeeId }}">{{ $employeeName }}</option>
                                @endforeach
                            </x-ui.select>

                            <x-ui.input
                                id="follow-up-start-{{ $review->id }}"
                                type="date"
                                :label="__('Starts')"
                                wire:model="followUp.{{ $review->id }}.startDate"
                            />
                            <x-ui.input
                                id="follow-up-due-{{ $review->id }}"
                                type="date"
                                :label="__('Due')"
                                wire:model="followUp.{{ $review->id }}.dueDate"
                            />
                        </div>

                        <x-ui.textarea
                            id="follow-up-objective-{{ $review->id }}"
                            rows="2"
                            :label="__('What the action has to achieve')"
                            wire:model="followUp.{{ $review->id }}.objective"
                        />
                        <x-ui.textarea
                            id="follow-up-intervention-{{ $review->id }}"
                            rows="2"
                            :label="__('What will be done')"
                            wire:model="followUp.{{ $review->id }}.intervention"
                        />
                        <x-ui.textarea
                            id="follow-up-evidence-{{ $review->id }}"
                            rows="2"
                            :label="__('What will show it worked')"
                            wire:model="followUp.{{ $review->id }}.evidence"
                        />

                        <x-ui.button type="button" wire:click="openFollowUp({{ $review->id }})">
                            {{ __('Open the follow-up action') }}
                        </x-ui.button>
                    </div>
                </x-ui.card>
            @endforeach
        @endif
    </section>
</div>
