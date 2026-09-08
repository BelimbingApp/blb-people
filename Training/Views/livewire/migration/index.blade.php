<div class="space-y-section-gap">
    <x-ui.page-header
        :title="__('Migration sources')"
        :subtitle="__('Every legacy source a production import may read from: its owner, format, volume, retention and data quality, signed by HR before any import runs.')"
    />

    @if (session('migration-status'))
        <x-ui.alert variant="success">{{ session('migration-status') }}</x-ui.alert>
    @endif
    @error('form')
        <x-ui.alert variant="danger">{{ $message }}</x-ui.alert>
    @enderror

    @if ($companies === [])
        <x-ui.alert variant="info">{{ __('No company is attributed to your role.') }}</x-ui.alert>
    @else
        @if (count($companies) > 1)
            <div class="flex flex-wrap gap-2 text-sm">
                @foreach ($companies as $entityId => $companyName)
                    <x-ui.button type="button" wire:click="selectCompany({{ $entityId }})" :variant="$companyEntityId === $entityId ? 'primary' : 'secondary'">
                        {{ $companyName }}
                    </x-ui.button>
                @endforeach
            </div>
        @endif

        <x-ui.alert :variant="$signed ? 'success' : 'warning'">
            @if ($signed)
                {{ __('The inventory is signed: every recorded source carries a sign-off.') }}
            @else
                {{ __('The inventory is not signed: a production import stays closed until every source is recorded and signed.') }}
            @endif
        </x-ui.alert>

        <x-ui.card>
            @if ($sources->isEmpty())
                <p class="text-sm text-muted">{{ __('No migration source is recorded for this company yet.') }}</p>
            @else
                <x-ui.table :caption="__('Migration source inventory')">
                    <x-slot:head>
                        <tr>
                            <x-ui.th>{{ __('Key') }}</x-ui.th>
                            <x-ui.th>{{ __('Source') }}</x-ui.th>
                            <x-ui.th>{{ __('Kind') }}</x-ui.th>
                            <x-ui.th>{{ __('Owner') }}</x-ui.th>
                            <x-ui.th>{{ __('Format') }}</x-ui.th>
                            <x-ui.th>{{ __('Volume') }}</x-ui.th>
                            <x-ui.th>{{ __('Retention') }}</x-ui.th>
                            <x-ui.th>{{ __('Data quality') }}</x-ui.th>
                            <x-ui.th>{{ __('Sign-off') }}</x-ui.th>
                        </tr>
                    </x-slot:head>
                    <x-slot:body>
                        @foreach ($sources as $source)
                            <tr wire:key="migration-source-{{ $source->id }}">
                                <td class="px-table-cell-x py-table-cell-y text-sm text-ink font-mono">{{ $source->source_key }}</td>
                                <td class="px-table-cell-x py-table-cell-y text-sm text-ink">{{ $source->name }}</td>
                                <td class="px-table-cell-x py-table-cell-y text-sm text-ink">{{ $source->kind->label() }}</td>
                                <td class="px-table-cell-x py-table-cell-y text-sm text-ink tabular-nums">{{ $source->owner_employee_id ?? '—' }}</td>
                                <td class="px-table-cell-x py-table-cell-y text-sm text-ink">{{ $source->format }}</td>
                                <td class="px-table-cell-x py-table-cell-y text-sm text-ink tabular-nums">{{ $source->estimated_volume ?? '—' }}</td>
                                <td class="px-table-cell-x py-table-cell-y text-sm text-muted">{{ $source->retention_note ?? '—' }}</td>
                                <td class="px-table-cell-x py-table-cell-y text-sm text-muted">{{ $source->data_quality_note ?? '—' }}</td>
                                <td class="px-table-cell-x py-table-cell-y text-sm space-y-2">
                                    @if ($source->signoff !== null)
                                        <x-ui.badge variant="success">{{ __('Signed :at', ['at' => $source->signoff->signed_at->toDateString()]) }}</x-ui.badge>
                                        @if ($source->signoff->note)
                                            <span class="block text-muted">{{ $source->signoff->note }}</span>
                                        @endif
                                    @elseif ($mayManage)
                                        <x-ui.input type="text" wire:model="signNote.{{ $source->id }}" :placeholder="__('Sign-off note (optional)')" />
                                        @error('sign.'.$source->id)
                                            <span class="block text-danger">{{ $message }}</span>
                                        @enderror
                                        <div class="flex gap-2">
                                            <x-ui.button type="button" variant="primary" wire:click="sign({{ $source->id }})">{{ __('Sign') }}</x-ui.button>
                                            <x-ui.button type="button" variant="secondary" wire:click="edit({{ $source->id }})">{{ __('Edit') }}</x-ui.button>
                                        </div>
                                    @else
                                        <span class="text-muted">{{ __('Unsigned') }}</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </x-slot:body>
                </x-ui.table>
            @endif
        </x-ui.card>

        @if ($mayManage)
            <x-ui.card>
                <h2 class="text-lg font-semibold">{{ $editingId === null ? __('Record a source') : __('Edit source #:id', ['id' => $editingId]) }}</h2>
                <div class="grid gap-3 md:grid-cols-2">
                    <x-ui.input type="text" wire:model="sourceKey" :label="__('Key')" :placeholder="__('e.g. legacy-portal')" />
                    <x-ui.input type="text" wire:model="name" :label="__('Name')" />
                    <x-ui.select wire:model="kind" :label="__('Kind')">
                        @foreach ($kinds as $option)
                            <option value="{{ $option->value }}">{{ $option->label() }}</option>
                        @endforeach
                    </x-ui.select>
                    <x-ui.input type="text" wire:model="format" :label="__('Format')" :placeholder="__('e.g. xlsx, csv, paper')" />
                    <x-ui.input type="text" wire:model="ownerEmployeeEntityId" :label="__('Owner (employee id)')" />
                    <x-ui.input type="text" wire:model="estimatedVolume" :label="__('Estimated volume (records)')" />
                    <x-ui.input type="text" wire:model="retentionNote" :label="__('Retention')" />
                    <x-ui.input type="text" wire:model="dataQualityNote" :label="__('Data quality')" />
                </div>
                <div class="flex gap-2 pt-3">
                    <x-ui.button type="button" variant="primary" wire:click="save">{{ $editingId === null ? __('Record') : __('Save') }}</x-ui.button>
                    @if ($editingId !== null)
                        <x-ui.button type="button" variant="secondary" wire:click="cancelEdit">{{ __('Cancel') }}</x-ui.button>
                    @endif
                </div>
            </x-ui.card>
        @endif
    @endif
</div>
