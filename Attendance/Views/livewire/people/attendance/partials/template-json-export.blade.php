@php
    /** @var string $id */
    /** @var string $field */
    $description ??= __('Copy this JSON into a shared template repository or country pack. Upload supports this same format.');
@endphp

<x-ui.card>
    <h3 class="text-base font-semibold text-ink">{{ __('Template JSON ready') }}</h3>
    <p class="mt-1 text-sm text-muted">{{ $description }}</p>
    <x-ui.textarea :id="$id" wire:model="{{ $field }}" label="{{ __('Template JSON') }}" rows="10" class="mt-4" />
</x-ui.card>
