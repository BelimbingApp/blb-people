@php($isEnrolled = in_array((int) $event->id, $enrolled, true))
@php($isFull = ($counts[$event->id] ?? 0) >= (int) $event->capacity && ! $isEnrolled)
@if (! $canSelfManageParticipation)
    <span class="text-xs text-muted">{{ __('Employee self-service unavailable') }}</span>
@elseif ($isEnrolled)
    <x-ui.button type="button" wire:click="withdraw({{ $event->id }})" variant="secondary">{{ __('Withdraw') }}</x-ui.button>
@elseif ($isFull)
    <span class="text-xs font-medium text-muted">{{ __('Full') }}</span>
@else
    <span class="text-xs text-muted">{{ __('Eligible') }}</span>
    <x-ui.button type="button" wire:click="enrol({{ $event->id }})" variant="secondary">{{ __('Enrol') }}</x-ui.button>
@endif
