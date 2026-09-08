<div class="space-y-section-gap">
    <x-ui.page-header :title="__('Define course')" :subtitle="__('Add a course to the company training catalog.')">
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
        @include('people::livewire.training.catalog._form', ['editing' => false])
    </x-ui.card>
</div>
