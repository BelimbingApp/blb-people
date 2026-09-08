<div class="space-y-section-gap">
    <x-ui.page-header
        :title="$skill === null ? __('Create skill') : __('Edit :skill', ['skill' => $skill->name])"
        :subtitle="__('Define the catalog standard, evidence expectations, and reassessment cadence for this company.')"
    >
        <x-slot:actions>
            @if ($skill !== null)
                <x-ui.record-history
                    :title="__('History for :skill', ['skill' => $skill->name])"
                    :subjects="[['name' => 'skill', 'id' => $skill->id]]"
                    :auditable-type="$skill->getMorphClass()"
                    :auditable-id="$skill->id"
                    source-capability="people.skill.catalog.view"
                />
            @endif
            <x-ui.button type="button" variant="ghost" wire:click="cancelForm">
                {{ __('Back to skills') }}
            </x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    <x-ui.card>
        <form wire:submit="saveSkill" class="space-y-6">
            @error('skillForm')
                <x-ui.alert variant="danger">{{ $message }}</x-ui.alert>
            @enderror

            <div class="grid gap-4 md:grid-cols-2">
                <x-ui.input
                    id="skill-code"
                    wire:model="skillForm.code"
                    :label="__('Skill ID (stable code)')"
                    :disabled="$editingSkillId !== null"
                    required
                    autofocus
                />
                <x-ui.input id="skill-name" wire:model="skillForm.name" :label="__('Name')" required />
                <x-ui.select id="skill-category" wire:model="skillForm.category_id" :label="__('Category')" required>
                    @foreach ($categories->where('active', true) as $category)
                        <option value="{{ $category->id }}">{{ $category->name }}</option>
                    @endforeach
                </x-ui.select>
                <x-ui.select id="skill-scope" wire:model="skillForm.scope" :label="__('Scope')" required>
                    @foreach ($scopeOptions as $scope)
                        <option value="{{ $scope->value }}">{{ ucfirst($scope->value) }}</option>
                    @endforeach
                </x-ui.select>
                <x-ui.select id="skill-critical-classification" wire:model="skillForm.critical_classification" :label="__('Critical classification')">
                    <option value="">{{ __('Not critical') }}</option>
                    @foreach ($classificationOptions as $classification)
                        <option value="{{ $classification->value }}">{{ ucfirst($classification->value) }}</option>
                    @endforeach
                </x-ui.select>
                <x-ui.select id="skill-assessment-method" wire:model="skillForm.default_assessment_method" :label="__('Default assessment method')" required>
                    @foreach ($methodOptions as $method)
                        <option value="{{ $method->value }}">{{ $method->label() }}</option>
                    @endforeach
                </x-ui.select>
                <x-ui.input
                    id="skill-reassessment-months"
                    type="number"
                    min="1"
                    wire:model="skillForm.default_reassessment_months"
                    :label="__('Reassessment cadence (months)')"
                />
            </div>

            <x-ui.textarea id="skill-definition" wire:model="skillForm.definition" :label="__('Definition / standard')" rows="3" required />
            <x-ui.textarea id="skill-evidence-guide" wire:model="skillForm.evidence_guide" :label="__('Minimum evidence guide')" rows="3" />

            <div class="flex flex-wrap items-center gap-3">
                <x-ui.button type="submit" variant="primary">
                    {{ $skill === null ? __('Create skill') : __('Save changes') }}
                </x-ui.button>
                <x-ui.button type="button" variant="ghost" wire:click="cancelForm">{{ __('Cancel') }}</x-ui.button>
            </div>
        </form>
    </x-ui.card>
</div>
