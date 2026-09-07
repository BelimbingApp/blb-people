@php($isEnrolled = in_array((int) $event->id, $enrolled, true))
@php($isFull = ($counts[$event->id] ?? 0) >= (int) $event->capacity && ! $isEnrolled)
@if ($isEnrolled)
    <x-ui.button type="button" wire:click="withdraw({{ $event->id }})" variant="secondary">{{ __('Withdraw') }}</x-ui.button>
@elseif ($isFull)
    <span class="text-xs font-medium text-muted">{{ __('Full') }}</span>
@else
    <x-ui.button type="button" wire:click="enrol({{ $event->id }})" variant="primary">{{ __('Enrol') }}</x-ui.button>
@endif
