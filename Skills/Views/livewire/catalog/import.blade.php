<div class="space-y-section-gap">
    <x-ui.page-header
        :title="__('Import starter profiles')"
        :subtitle="__('Upload one CSV workbook of starter role requirements. Every row is checked first; a single bad row refuses the whole file and nothing is written.')"
    />

    @if ($companies === [])
        <x-ui.alert variant="info">{{ __('No company is attributed to your HR role.') }}</x-ui.alert>
    @else
        @if (count($companies) > 1)
            <div class="flex flex-wrap gap-2 text-sm">
                @foreach ($companies as $entityId => $name)
                    <x-ui.button type="button" wire:click="selectCompany({{ $entityId }})" :variant="$companyEntityId === $entityId ? 'primary' : 'secondary'">
                        {{ $name }}
                    </x-ui.button>
                @endforeach
            </div>
        @endif

        <x-ui.card>
            <p class="text-sm text-muted">
                {{ __('Columns, in this order: :columns. Required level is 1 to 5; criticality is critical, essential or development. Department names must match the company directory. Each (department, role) becomes one draft requirement profile; unknown skills are created in the "Imported" category. Re-uploading the same file changes nothing.', ['columns' => implode(', ', $columns)]) }}
            </p>
            <form wire:submit="import" class="mt-4 flex flex-wrap items-end gap-4">
                <label class="block text-sm">
                    <span class="block text-[11px] uppercase tracking-wider font-semibold text-muted">{{ __('CSV workbook') }}</span>
                    <input type="file" wire:model="workbook" accept=".csv,text/csv" class="mt-1 block text-sm" />
                </label>
                <x-ui.button type="submit" variant="primary">{{ __('Validate and import') }}</x-ui.button>
                <span wire:loading wire:target="workbook,import" class="text-sm text-muted">{{ __('Working…') }}</span>
            </form>
            @error('workbook')
                <p class="mt-2 text-sm text-status-danger">{{ $message }}</p>
            @enderror
        </x-ui.card>

        @if ($result !== null && $result['errors'] !== [])
            <x-ui.alert variant="error">
                {{ __(':file was refused: :count problem(s) in :rows rows. Nothing was written.', ['file' => $result['file'], 'count' => count($result['errors']), 'rows' => $result['rows']]) }}
            </x-ui.alert>
            <x-ui.table :caption="__('Rows refused in :file', ['file' => $result['file']])" caption-position="top">
                <x-slot:head>
                    <tr>
                        <x-ui.th>{{ __('Row') }}</x-ui.th>
                        <x-ui.th>{{ __('Problem') }}</x-ui.th>
                    </tr>
                </x-slot:head>
                <x-slot:body>
                    @foreach ($result['errors'] as $index => $error)
                        <tr wire:key="import-error-{{ $index }}">
                            <td class="px-table-cell-x py-table-cell-y text-sm text-ink tabular-nums">{{ $error['row'] }}</td>
                            <td class="px-table-cell-x py-table-cell-y text-sm text-ink">{{ $error['message'] }}</td>
                        </tr>
                    @endforeach
                </x-slot:body>
            </x-ui.table>
        @elseif ($result !== null)
            <x-ui.alert variant="success">
                {{ __(':file imported: :rows rows, :skills new skills, :profiles new role profiles, :requirements new requirements.', ['file' => $result['file'], 'rows' => $result['rows'], 'skills' => $result['skills'], 'profiles' => $result['profiles'], 'requirements' => $result['requirements']]) }}
            </x-ui.alert>
        @endif
    @endif
</div>
