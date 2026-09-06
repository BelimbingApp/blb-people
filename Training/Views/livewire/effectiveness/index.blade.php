<div class="space-y-section-gap">
    <x-ui.page-header
        :title="__('Training effectiveness')"
        :subtitle="__('Thirty, sixty and ninety days after your team attended a course, say whether it is being used.')"
    />

    @if (session('training-effectiveness-status'))
        <x-ui.alert variant="success">{{ session('training-effectiveness-status') }}</x-ui.alert>
    @endif
    @error('effectiveness')
        <x-ui.alert variant="danger">{{ $message }}</x-ui.alert>
    @enderror

    @if ($rows === [])
        <x-ui.alert variant="info">{{ __('No effectiveness question is open for your department.') }}</x-ui.alert>
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
</div>
