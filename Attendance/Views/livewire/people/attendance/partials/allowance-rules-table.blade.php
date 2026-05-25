<x-ui.card>
    <div class="overflow-x-auto -mx-card-inner px-card-inner">
        <table class="min-w-full divide-y divide-border-default text-sm">
            <thead class="bg-surface-subtle/80">
                <tr>
                    <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold uppercase tracking-wider text-muted">{{ __('No.') }}</th>
                    <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold uppercase tracking-wider text-muted">{{ __('Allowance rule') }}</th>
                    <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold uppercase tracking-wider text-muted">{{ __('Status') }}</th>
                    <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold uppercase tracking-wider text-muted">{{ __('Scope') }}</th>
                    <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold uppercase tracking-wider text-muted">{{ __('Amount') }}</th>
                    <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold uppercase tracking-wider text-muted">{{ __('Effective') }}</th>
                    <th class="px-table-cell-x py-table-header-y text-right text-[11px] font-semibold uppercase tracking-wider text-muted">{{ __('Actions') }}</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-border-default bg-surface-card">
                @forelse ($allowanceRules as $rule)
                    <tr wire:key="allowance-rule-row-{{ $rule->id }}">
                        <td class="px-table-cell-x py-table-cell-y text-xs text-muted tabular-nums">{{ $loop->iteration }}</td>
                        <td class="px-table-cell-x py-table-cell-y">
                            <button type="button" class="text-left font-medium text-accent hover:underline" wire:click="editAllowanceRule({{ $rule->id }})">{{ $rule->name }}</button>
                            <div class="font-mono text-xs text-muted">{{ $rule->code }}</div>
                        </td>
                        <td class="px-table-cell-x py-table-cell-y">
                            <x-ui.button type="button" size="sm" :variant="$rule->status === 'active' ? 'primary' : 'secondary'" wire:click="toggleAllowanceStatus({{ $rule->id }})">{{ __(ucfirst($rule->status)) }}</x-ui.button>
                        </td>
                        <td class="px-table-cell-x py-table-cell-y text-xs text-muted">
                            <div>{{ __('Policy: :policy', ['policy' => $rule->policyGroup?->code ?? __('Any')]) }}</div>
                            <div>{{ __('Shift: :shift', ['shift' => $rule->shiftTemplate?->code ?? __('Any')]) }}</div>
                        </td>
                        <td class="px-table-cell-x py-table-cell-y text-xs text-muted">
                            <div>{{ $rule->condition_rows[0]['amount'] ?? '-' }}</div>
                            <div>{{ __(ucfirst($rule->allowance_type)) }} / {{ __(ucfirst($rule->resolution_method)) }}</div>
                        </td>
                        <td class="px-table-cell-x py-table-cell-y text-xs text-muted">{{ $rule->effective_from?->format('Y-m-d') ?? '-' }}</td>
                        <td class="px-table-cell-x py-table-cell-y">
                            <div class="flex justify-end gap-2">
                                <x-ui.button type="button" size="sm" variant="secondary" wire:click="duplicateAllowanceRule({{ $rule->id }})">{{ __('Duplicate') }}</x-ui.button>
                                <x-ui.button type="button" size="sm" variant="danger" wire:click="deleteAllowanceRule({{ $rule->id }})" wire:confirm="{{ __('Delete this allowance rule?') }}">{{ __('Delete') }}</x-ui.button>
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="px-table-cell-x py-10 text-center text-sm text-muted">{{ __('No allowance rules configured. Start from a template or duplicate an existing rule when one is available.') }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</x-ui.card>
