@php
    /** @var string $field */
    /** @var string $id */
    /** @var string $label */
    /** @var string $help */
    $required ??= false;
@endphp

@if ($payrollPayItems->isNotEmpty())
    <x-ui.select :id="$id" wire:model="{{ $field }}" :label="$label" :required="$required" :help="$help" :error="$errors->first($field)">
        <option value="">{{ __('Choose pay item') }}</option>
        @foreach ($payrollPayItems as $payItem)
            <option value="{{ $payItem->code }}">{{ $payItem->code }} — {{ $payItem->name }}</option>
        @endforeach
    </x-ui.select>
@else
    <x-ui.input :id="$id" wire:model="{{ $field }}" :label="$label" :required="$required" help="{{ __('Create payroll pay items first to get selectable codes here.') }}" :error="$errors->first($field)" />
@endif
