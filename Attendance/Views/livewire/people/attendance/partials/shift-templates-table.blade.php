@if ($shiftTemplateExportJson !== '')
    @include('people-attendance::livewire.people.attendance.partials.template-json-export', [
        'id' => 'attendance-shift-template-export',
        'field' => 'shiftTemplateExportJson',
    ])
@endif

<x-ui.card>
    <x-ui.table container="flush" :caption="__('Shift templates')">

        <x-slot name="head">
                <tr>
                    <x-ui.th>{{ __('No.') }}</x-ui.th>
                    <x-ui.th>{{ __('Shift') }}</x-ui.th>
                    <x-ui.th>{{ __('Status') }}</x-ui.th>
                    <x-ui.th>{{ __('Schedule') }}</x-ui.th>
                    <x-ui.th>{{ __('Effective') }}</x-ui.th>
                    <x-ui.th align="right">{{ __('Actions') }}</x-ui.th>
                </tr>
            </x-slot>

                @forelse ($shiftTemplates as $shift)
                    <tr wire:key="shift-template-row-{{ $shift->id }}">
                        <td class="px-table-cell-x py-table-cell-y text-xs text-muted tabular-nums">{{ $loop->iteration }}</td>
                        <td class="px-table-cell-x py-table-cell-y">
                            <button type="button" class="text-left font-medium text-accent hover:underline" wire:click="editShiftTemplate({{ $shift->id }})">{{ $shift->name }}</button>
                            <div class="font-mono text-xs text-muted">{{ $shift->code }}</div>
                        </td>
                        <td class="px-table-cell-x py-table-cell-y">
                            <x-ui.button type="button" size="sm" :variant="$shift->status === 'active' ? 'primary' : 'secondary'" wire:click="toggleShiftStatus({{ $shift->id }})">{{ __(ucfirst($shift->status)) }}</x-ui.button>
                        </td>
                        <td class="px-table-cell-x py-table-cell-y font-mono text-xs text-muted">
                            {{ $shift->starts_at }} → {{ $shift->ends_at }}
                            @if ($shift->crosses_midnight)
                                <x-ui.badge variant="warning" class="ml-1">{{ __('Cross-midnight') }}</x-ui.badge>
                            @endif
                        </td>
                        <td class="px-table-cell-x py-table-cell-y text-xs text-muted">{{ $shift->effective_from?->format('Y-m-d') ?? '-' }}</td>
                        <td class="px-table-cell-x py-table-cell-y">
                            <div class="flex justify-end gap-2">
                                <x-ui.button type="button" size="sm" variant="secondary" wire:click="duplicateShiftTemplate({{ $shift->id }})">{{ __('Duplicate') }}</x-ui.button>
                                <x-ui.button type="button" size="sm" variant="secondary" wire:click="exportShiftTemplate({{ $shift->id }})">{{ __('Download') }}</x-ui.button>
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="px-table-cell-x py-10 text-center text-sm text-muted">{{ __('No shift templates configured. Start from a template or create a new shift.') }}</td></tr>
                @endforelse

    </x-ui.table>
</x-ui.card>
