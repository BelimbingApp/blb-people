{{-- As-of date control. Emits standing-as-of-changed; runs no queries itself. --}}
<div class="inline-flex items-center gap-2 text-sm">
    <label for="people-shared-as-of-date" class="font-medium text-stone-700">{{ __('As of') }}</label>
    <input
        id="people-shared-as-of-date"
        type="date"
        wire:model.live="date"
        max="{{ now()->toDateString() }}"
        class="rounded-md border border-stone-300 bg-white px-2 py-1 text-sm text-stone-800"
    />
    @error('date')
        <span class="text-xs text-red-700">{{ $message }}</span>
    @enderror
</div>
