<div class="space-y-section-gap">
    <x-ui.page-header :title="__('Revise course')" :subtitle="__('Update the company training catalog entry.')">
        <x-slot:actions>
            <x-ui.record-history
                :title="__('History for :name', ['name' => $course->title])"
                :subjects="[['name' => 'training_course', 'id' => $course->id]]"
                :auditable-type="$course->getMorphClass()"
                :auditable-id="$course->id"
                :source-capability="\App\Domains\People\Training\Services\TrainingAudience::MANAGE"
                :require-audit-list-capability="false"
            />
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
