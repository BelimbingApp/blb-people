@php
    /** @var \App\Domains\People\Training\Livewire\Catalog\Create|\App\Domains\People\Training\Livewire\Catalog\Edit $this */
    $isEdit = isset($editing);
@endphp

<form wire:submit="saveCourse" class="space-y-3">
    <div class="grid gap-3 md:grid-cols-2">
        <x-ui.input
            id="training-course-code"
            :label="__('Training ID (stable code)')"
            :error="$errors->first('courseForm.code')"
            wire:model="courseForm.code"
            :disabled="$isEdit"
            required
        />
        <x-ui.input
            id="training-course-title"
            :label="__('Training title')"
            :error="$errors->first('courseForm.title')"
            wire:model="courseForm.title"
            required
        />
        <x-ui.select
            id="training-course-delivery-mode"
            :label="__('Delivery mode')"
            :error="$errors->first('courseForm.delivery_mode')"
            wire:model="courseForm.delivery_mode"
            required
        >
            @foreach ($deliveryModes as $mode)
                <option value="{{ $mode->value }}">{{ $mode->label() }}</option>
            @endforeach
        </x-ui.select>
        <x-ui.select
            id="training-course-trainer"
            :label="__('Internal trainer / mentor')"
            :error="$errors->first('courseForm.internal_trainer_employee_entity_id')"
            wire:model="courseForm.internal_trainer_employee_entity_id"
        >
            <option value="">{{ __('Not assigned') }}</option>
            @foreach ($employees as $employee)
                <option value="{{ $employee->workforce_entity_id }}">{{ $employee->display_name }}</option>
            @endforeach
        </x-ui.select>
    </div>
    <div class="space-y-1">
        <span class="block text-[11px] font-semibold uppercase tracking-wider text-muted">
            {{ __('Skills covered') }} <span class="text-status-danger">*</span>
        </span>
        <x-ui.multi-select
            id="training-course-skills"
            :accessible-label="__('Skills covered (required)')"
            :placeholder="__('Select at least one skill')"
            :options="$skills->map(fn ($skill) => ['value' => $skill->id, 'label' => $skill->code.' · '.$skill->name])->all()"
            :selected="$courseForm['skill_ids'] ?? []"
            wire:model="courseForm.skill_ids"
        />
        <p class="text-sm text-muted">{{ __('Select one or more active skills this course develops.') }}</p>
    </div>
    @error('courseForm.skill_ids')
        <p class="text-sm text-status-danger">{{ $message }}</p>
    @enderror
    <x-ui.textarea
        id="training-course-description"
        :label="__('Description')"
        :error="$errors->first('courseForm.description')"
        wire:model="courseForm.description"
        rows="3"
    />
    <div class="flex gap-2">
        <x-ui.button type="submit">{{ __('Save course') }}</x-ui.button>
        <x-ui.button type="button" variant="secondary" wire:click="cancel">{{ __('Cancel') }}</x-ui.button>
    </div>
</form>
