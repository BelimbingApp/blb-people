<div class="space-y-6">
    <x-ui.card>
        <div class="flex flex-wrap items-center justify-between gap-2">
            <div>
                <h2 class="text-lg font-semibold text-ink">{{ __('Claim type pay-item mapping') }}</h2>
                <p class="text-sm text-muted">{{ __('Assign payroll pay-item codes to payroll-eligible claim types.') }}</p>
            </div>
            <a href="{{ route('people.claim.workbench') }}" class="text-sm text-accent hover:underline">{{ __('Open Claim types') }}</a>
        </div>

        @if (session('success'))
            <div class="mt-3 rounded-2xl border border-success-border bg-success-surface px-4 py-2 text-sm text-success-ink">{{ session('success') }}</div>
        @endif

        <div class="mt-4 overflow-x-auto">
            <table class="w-full text-left text-sm">
                <thead class="text-xs uppercase tracking-wide text-muted">
                    <tr>
                        <th class="px-table-cell-x py-table-cell-y">{{ __('Claim type') }}</th>
                        <th class="px-table-cell-x py-table-cell-y">{{ __('Current pay-item') }}</th>
                        <th class="px-table-cell-x py-table-cell-y">{{ __('History') }}</th>
                        <th class="px-table-cell-x py-table-cell-y"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-border-default bg-surface-card">
                    @forelse ($types as $type)
                        @php
                            $mappings = $mappingsByType->get($type->id, collect());
                            $current = $mappings->first();
                        @endphp
                        <tr wire:key="claim-type-{{ $type->id }}">
                            <td class="px-table-cell-x py-table-cell-y">
                                <div class="font-medium text-ink">{{ $type->name }}</div>
                                <div class="font-mono text-xs text-muted">{{ $type->code }}</div>
                            </td>
                            <td class="px-table-cell-x py-table-cell-y text-sm">
                                @if ($current)
                                    <div class="font-mono">{{ $current->payroll_pay_item_code }}</div>
                                    <div class="text-xs text-muted">{{ __('Effective :date', ['date' => $current->effective_from?->toDateString()]) }}</div>
                                @else
                                    <span class="text-xs text-muted">{{ __('No mapping — payroll handoff will skip this claim type.') }}</span>
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
                        <tr><td colspan="4" class="px-table-cell-x py-table-cell-y text-sm text-muted">{{ __('No payroll-eligible claim types defined.') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-ui.card>

    @if ($editingClaimTypeId > 0)
        <x-ui.card>
            <h3 class="text-base font-semibold text-ink">{{ __('Edit mapping') }}</h3>
            <form wire:submit.prevent="saveMapping" class="mt-3 grid gap-4 lg:grid-cols-3">
                <x-ui.select id="claim-mapping-pay-item" wire:model="editingPayItemCode" label="{{ __('Pay-item code') }}" :error="$errors->first('editingPayItemCode')">
                    <option value="">{{ __('Select a pay item') }}</option>
                    @foreach ($payItems as $item)
                        <option value="{{ $item->code }}">{{ $item->code }} — {{ $item->name }}</option>
                    @endforeach
                </x-ui.select>
                <x-ui.input id="claim-mapping-effective-from" type="date" wire:model="editingEffectiveFrom" label="{{ __('Effective from') }}" :error="$errors->first('editingEffectiveFrom')" />
                <div class="flex items-end gap-2">
                    <x-ui.button type="submit" variant="primary" :disabled="! $canManage">{{ __('Save mapping') }}</x-ui.button>
                    <x-ui.button type="button" variant="secondary" wire:click="cancelEditing">{{ __('Cancel') }}</x-ui.button>
                </div>
            </form>
        </x-ui.card>
    @endif
</div>
