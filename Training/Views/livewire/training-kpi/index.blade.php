<div class="space-y-section-gap">
    <x-ui.page-header
        :title="__('HR training KPI dashboard')"
        :subtitle="__('The revised-workbook training controls for this company and each department, with the definition, as-of and drill-down behind every number.')"
    />

    @if ($companies === [])
        <x-ui.alert variant="info">{{ __('No company workforce data is synchronized yet.') }}</x-ui.alert>
    @else
        <div class="flex flex-wrap items-center justify-between gap-4 text-sm">
            @if (count($companies) > 1)
                <div class="flex items-center gap-2">
                    <span class="text-muted">{{ __('Company') }}</span>
                    @foreach ($companies as $entityId => $name)
                        <x-ui.button type="button" wire:click="selectCompany({{ $entityId }})" :variant="$companyEntityId === $entityId ? 'primary' : 'secondary'">{{ $name }}</x-ui.button>
                    @endforeach
                </div>
            @endif
            <livewire:people-shared.as-of-date-picker :date="$asOf" :key="'as-of-'.$asOf" />
        </div>

        @php
            $format = static fn (array $metric, string $kind): string => match (true) {
                $metric['value'] === null => __('n/a'),
                $kind === 'rate' => number_format($metric['value'] * 100, 1).'%',
                $kind === 'mean', $kind === 'hours' => number_format($metric['value'], 1),
                default => (string) $metric['value'],
            };
            $title = static fn (array $metric): string => $metric['definition'].' '.__('As of :date.', ['date' => $metric['as_of']->format('Y-m-d')]);
            $href = static fn (array $metric): string => route($metric['drill']['route'], $metric['drill']['params']);
        @endphp

        <x-ui.card>
            <p class="text-sm text-muted" data-kpi-as-of="{{ $summary->asOf->format('Y-m-d') }}">
                {{ __('As of :date. Rates and means read n/a when nothing was attended, completed or closed.', ['date' => $summary->asOf->format('Y-m-d')]) }}
            </p>
            <x-ui.table container="flush" :caption="__('Company training controls')">
                <x-slot name="head">
                    <tr>
                        <x-ui.th>{{ __('Control') }}</x-ui.th>
                        <x-ui.th align="right">{{ __('Value') }}</x-ui.th>
                        <x-ui.th>{{ __('Definition') }}</x-ui.th>
                        <x-ui.th>{{ __('As of') }}</x-ui.th>
                    </tr>
                </x-slot>
                @foreach ($companyMetrics as $key => [$label, $definition, $kind])
                    @php($metric = $summary->company[$key])
                    <tr wire:key="company-{{ $key }}" data-kpi="{{ $key }}" data-kpi-value="{{ $metric['value'] ?? 'null' }}">
                        <td class="px-table-cell-x py-table-cell-y text-sm font-medium text-ink">{{ __($label) }}</td>
                        <td class="px-table-cell-x py-table-cell-y text-right text-sm tabular-nums text-ink" title="{{ $title($metric) }}">
                            <x-ui.link :href="$href($metric)">{{ $format($metric, $kind) }}</x-ui.link>
                        </td>
                        <td class="px-table-cell-x py-table-cell-y text-sm text-muted">{{ $metric['definition'] }}</td>
                        <td class="px-table-cell-x py-table-cell-y text-sm text-muted">{{ $metric['as_of']->format('Y-m-d') }}</td>
                    </tr>
                @endforeach
            </x-ui.table>
        </x-ui.card>

        <x-ui.card>
            <p class="text-sm text-muted">{{ __('Each department row uses the definition in its column heading; every cell opens the page that lists its records.') }}</p>
            <x-ui.table container="flush" :caption="__('Training KPIs by department')">
                <x-slot name="head">
                    <tr>
                        <x-ui.th>{{ __('Department') }}</x-ui.th>
                        @foreach ($departmentMetrics as $key => [$label, $definition, $kind])
                            <x-ui.th align="right"><abbr title="{{ $definition }}">{{ __($label) }}</abbr></x-ui.th>
                        @endforeach
                    </tr>
                </x-slot>
                @forelse ($summary->departments as $row)
                    @php($scope = (string) ($row['department_entity_id'] ?? 'none'))
                    <tr wire:key="department-{{ $scope }}" data-kpi-department="{{ $scope }}">
                        <td class="px-table-cell-x py-table-cell-y text-sm font-medium text-ink">{{ $row['department'] }}</td>
                        @foreach ($departmentMetrics as $key => [$label, $definition, $kind])
                            @php($metric = $row['metrics'][$key])
                            <td class="px-table-cell-x py-table-cell-y text-right text-sm tabular-nums text-ink" title="{{ $title($metric) }}" data-kpi="{{ $key }}" data-kpi-value="{{ $metric['value'] ?? 'null' }}">
                                <x-ui.link :href="$href($metric)">{{ $format($metric, $kind) }}</x-ui.link>
                            </td>
                        @endforeach
                    </tr>
                @empty
                    <tr>
                        <td colspan="{{ count($departmentMetrics) + 1 }}" class="px-table-cell-x py-10 text-center text-sm text-muted">
                            {{ __('No requests, attendance, evaluations or effectiveness reviews are recorded on or before this date.') }}
                        </td>
                    </tr>
                @endforelse
            </x-ui.table>
        </x-ui.card>
    @endif
</div>
