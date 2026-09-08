<div class="space-y-section-gap">
    <x-ui.page-header :title="__('Revise course')" :subtitle="__('Update the company training catalog entry.')">
        <x-slot:actions>
            <x-ui.link href="{{ $indexUrl }}" wire:navigate>
                {{ __('Back') }}
            </x-ui.link>
        </x-slot:actions>
    </x-ui.page-header>

    @error('courseForm')
        <x-ui.alert variant="danger">{{ $message }}</x-ui.alert>
    @enderror

    <x-ui.card>
        @include('people::livewire.training.catalog._form', ['editing' => true])
    </x-ui.card>
</div>
