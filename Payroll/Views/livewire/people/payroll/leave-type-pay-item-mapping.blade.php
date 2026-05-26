<div class="space-y-6">
    <x-ui.card>
        <div class="flex flex-wrap items-center justify-between gap-2">
            <div>
                <h2 class="text-lg font-semibold text-ink">{{ __('Leave type pay-item mapping') }}</h2>
                <p class="text-sm text-muted">{{ __('Assign payroll pay-item codes to leave types whose payouts feed the payroll engine.') }}</p>
            </div>
            <a href="{{ route('people.leave.index') }}" class="text-sm text-accent hover:underline">{{ __('Open Leave types') }}</a>
        </div>

        @if (session('success'))
            <div class="mt-3 rounded-2xl border border-success-border bg-success-surface px-4 py-2 text-sm text-success-ink">{{ session('success') }}</div>
        @endif

        <x-ui.table container="plain" :caption="__('Leave type mapping')">


            <x-slot name="head">
                    <tr>
                        <x-ui.th>{{ __('Leave type') }}</x-ui.th>
                        <x-ui.th>{{ __('Current pay-item') }}</x-ui.th>
                        <x-ui.th>{{ __('History') }}</x-ui.th>
                        <x-ui.th><span class="sr-only">{{ __('Actions') }}</span></x-ui.th>
                    </tr>
                </x-slot>

                    @forelse ($types as $type)
                        @php
                            $mappings = $mappingsByType->get($type->id, collect());
                            $current = $mappings->first();
                        @endphp
                        <tr wire:key="type-{{ $type->id }}">
                            <td class="px-table-cell-x py-table-cell-y">
                                <div class="font-medium text-ink">{{ $type->name }}</div>
                                <div class="font-mono text-xs text-muted">{{ $type->code }}</div>
                            </td>
                            <td class="px-table-cell-x py-table-cell-y text-sm">
                                @if ($current)
                                    <div class="font-mono">{{ $current->payroll_pay_item_code }}</div>
                                    <div class="text-xs text-muted">{{ __('Effective :date', ['date' => $current->effective_from?->toDateString()]) }}</div>
                                @else
                                    <span class="text-xs text-muted">{{ __('No mapping — payroll handoff will skip this leave type.') }}</span>
                                @endif
                            </td>
                            <td class="px-table-cell-x py-table-cell-y text-xs text-muted">
                                @if ($mappings->count() > 1)
                                    @foreach ($mappings->slice(1) as $past)
                                        <div>{{ $past->payroll_pay_item_code }} — {{ $past->effective_from?->toDateString() }}</div>
                                    @endforeach
                                @else
                                    <span class="text-xs text-muted">—</span>
                                @endif
                            </td>
                            <td class="px-table-cell-x py-table-cell-y text-right">
                                <x-ui.button type="button" size="sm" variant="secondary" wire:click="startEditing({{ $type->id }})" :disabled="! $canManage">
                                    {{ $current ? __('Update') : __('Assign') }}
                                </x-ui.button>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="px-table-cell-x py-table-cell-y text-sm text-muted">{{ __('No payroll-interacting leave types defined.') }}</td></tr>
                    @endforelse


        </x-ui.table>
    </x-ui.card>

    @if ($editingLeaveTypeId > 0)
        <x-ui.card>
            <h3 class="text-base font-semibold text-ink">{{ __('Edit mapping') }}</h3>
            <form wire:submit.prevent="saveMapping" class="mt-3 grid gap-4 lg:grid-cols-3">
                <x-ui.select id="leave-mapping-pay-item" wire:model="editingPayItemCode" label="{{ __('Pay-item code') }}" :error="$errors->first('editingPayItemCode')">
                    <option value="">{{ __('Select a pay item') }}</option>
                    @foreach ($payItems as $item)
                        <option value="{{ $item->code }}">{{ $item->code }} — {{ $item->name }}</option>
                    @endforeach
                </x-ui.select>
                <x-ui.input id="leave-mapping-effective-from" type="date" wire:model="editingEffectiveFrom" label="{{ __('Effective from') }}" :error="$errors->first('editingEffectiveFrom')" />
                <div class="flex items-end gap-2">
                    <x-ui.button type="submit" variant="primary" :disabled="! $canManage">{{ __('Save mapping') }}</x-ui.button>
                    <x-ui.button type="button" variant="secondary" wire:click="cancelEditing">{{ __('Cancel') }}</x-ui.button>
                </div>
            </form>
        </x-ui.card>
    @endif
</div>
