<div class="space-y-section-gap">
    <x-ui.page-header
        :title="$category === null ? __('Create category') : __('Edit :category', ['category' => $category->name])"
        :subtitle="__('Group related skills under a stable catalog code and a clear display name.')"
    >
        <x-slot:actions>
            @if ($category !== null)
                <x-ui.record-history
                    :title="__('History for :category', ['category' => $category->name])"
                    :subjects="[['name' => 'skill_category', 'id' => $category->id]]"
                    :auditable-type="$category->getMorphClass()"
                    :auditable-id="$category->id"
                    source-capability="people.skill.catalog.view"
                />
            @endif
            <x-ui.button type="button" variant="ghost" wire:click="cancelForm">
                {{ __('Back to categories') }}
            </x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    <x-ui.card>
        <form wire:submit="saveCategory" class="space-y-6">
            @error('categoryForm')
                <x-ui.alert variant="danger">{{ $message }}</x-ui.alert>
            @enderror

            <div class="grid gap-4 md:grid-cols-2">
                <x-ui.input
                    id="category-code"
                    wire:model="categoryForm.code"
                    :label="__('Code')"
                    :disabled="$editingCategoryId !== null"
                    required
                    autofocus
                />
                <x-ui.input id="category-name" wire:model="categoryForm.name" :label="__('Name')" required />
            </div>

            <div class="flex flex-wrap items-center gap-3">
                <x-ui.button type="submit" variant="primary">
                    {{ $category === null ? __('Create category') : __('Save changes') }}
                </x-ui.button>
                <x-ui.button type="button" variant="ghost" wire:click="cancelForm">{{ __('Cancel') }}</x-ui.button>
            </div>
        </form>
    </x-ui.card>
</div>
