<div class="space-y-section-gap">
    <x-ui.page-header
        :title="__('Training requests register')"
        :subtitle="__('Every training request of the company for :year, with its status, estimated cost, department and decision. Filters narrow the list and the CSV export.', ['year' => $year])"
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

        <div class="grid gap-4 md:grid-cols-3">
            <x-ui.select wire:model.live="status" :label="__('Status')">
                <option value="">{{ __('All statuses') }}</option>
                @foreach ($statuses as $case)
                    <option value="{{ $case->value }}">{{ $case->value }}</option>
                @endforeach
            </x-ui.select>
            <x-ui.select wire:model.live="department" :label="__('Department')">
                <option value="">{{ __('All departments') }}</option>
                @foreach ($departments as $unitId => $name)
                    <option value="{{ $unitId }}">{{ $name }}</option>
                @endforeach
            </x-ui.select>
            <div class="flex items-end">
                <x-ui.button type="button" variant="secondary" wire:click="export">{{ __('Export CSV (:count rows)', ['count' => $rows->count()]) }}</x-ui.button>
            </div>
        </div>

        @if ($rows->isEmpty())
            <p class="text-sm text-muted">{{ __('No training request matches.') }}</p>
        @else
            <x-ui.table :caption="__('Training requests register')">
                <x-slot:head>
                    <tr>
                        <x-ui.th>{{ __('Created') }}</x-ui.th>
                        <x-ui.th>{{ __('Requestor') }}</x-ui.th>
                        <x-ui.th>{{ __('Department') }}</x-ui.th>
                        <x-ui.th>{{ __('Need') }}</x-ui.th>
                        <x-ui.th>{{ __('Status') }}</x-ui.th>
                        <x-ui.th>{{ __('Estimated cost') }}</x-ui.th>
                        <x-ui.th>{{ __('Approver') }}</x-ui.th>
                        <x-ui.th>{{ __('Decided') }}</x-ui.th>
                    </tr>
                </x-slot:head>
                <x-slot:body>
                    @foreach ($rows as $row)
                        <tr wire:key="training-request-row-{{ $row['id'] }}">
                            <td class="px-table-cell-x py-table-cell-y text-sm text-ink tabular-nums">{{ $row['created_at'] }}</td>
                            <td class="px-table-cell-x py-table-cell-y text-sm text-ink">{{ $row['requestor'] }}</td>
                            <td class="px-table-cell-x py-table-cell-y text-sm text-ink">{{ $row['department'] }}</td>
                            <td class="px-table-cell-x py-table-cell-y text-sm text-ink">{{ $row['need'] }}</td>
                            <td class="px-table-cell-x py-table-cell-y text-sm text-ink"><span data-status="{{ $row['status'] }}">{{ $row['status'] }}</span></td>
                            <td class="px-table-cell-x py-table-cell-y text-sm text-ink tabular-nums">{{ $row['estimated_cost'] }}</td>
                            <td class="px-table-cell-x py-table-cell-y text-sm text-ink">{{ $row['approver'] }}</td>
                            <td class="px-table-cell-x py-table-cell-y text-sm text-ink tabular-nums">{{ $row['decided_at'] }}</td>
                        </tr>
                    @endforeach
                </x-slot:body>
            </x-ui.table>
        @endif
    @endif
</div>
