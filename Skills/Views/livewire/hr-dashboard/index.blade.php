<div class="space-y-section-gap">
    <x-ui.page-header
        :title="__('HR skill KPI dashboard')"
        :subtitle="__('The contractual workbook metrics for this company and each department, with the definition and as-of behind every number.')"
    />

    @if ($companies === [])
        <x-ui.alert variant="info">{{ __('No company workforce data is synchronized yet.') }}</x-ui.alert>
    @else
        @if (count($companies) > 1)
            <div class="flex items-center gap-2 text-sm">
                <span class="text-muted">{{ __('Company') }}</span>
                @foreach ($companies as $entityId => $name)
                    <x-ui.button type="button" wire:click="selectCompany({{ $entityId }})" :variant="$companyEntityId === $entityId ? 'primary' : 'secondary'">{{ $name }}</x-ui.button>
                @endforeach
            </div>
        @endif

        @php
            $format = static fn (array $metric, string $kind): string => $kind === 'rate'
                ? ($metric['value'] === null ? __('n/a') : number_format($metric['value'] * 100, 1).'%')
                : (string) $metric['value'];
            $title = static fn (array $metric): string => $metric['definition'].' '.__('As of :moment.', ['moment' => $metric['as_of']->format('Y-m-d H:i')]);
        @endphp

        <x-ui.card>
            <p class="text-sm text-muted" data-kpi-as-of="{{ $summary->asOf->format('Y-m-d H:i:s') }}">
                {{ __('As of :moment. Rates read n/a when nothing was expected or recorded.', ['moment' => $summary->asOf->format('Y-m-d H:i')]) }}
            </p>
            <x-ui.table container="flush" :caption="__('Company skill KPIs')">
                <x-slot name="head">
                    <tr>
                        <x-ui.th>{{ __('Metric') }}</x-ui.th>
                        <x-ui.th align="right">{{ __('Value') }}</x-ui.th>
                        <x-ui.th>{{ __('Definition') }}</x-ui.th>
                        <x-ui.th>{{ __('As of') }}</x-ui.th>
                    </tr>
                </x-slot>
                @foreach ($metrics as $key => [$label, $definition, $kind])
                    @php($metric = $summary->company[$key])
                    <tr wire:key="company-{{ $key }}" data-kpi="{{ $key }}" data-kpi-value="{{ $metric['value'] ?? 'null' }}">
                        <td class="px-table-cell-x py-table-cell-y text-sm font-medium text-ink">{{ __($label) }}</td>
                        <td class="px-table-cell-x py-table-cell-y text-right text-sm tabular-nums text-ink" title="{{ $title($metric) }}">
                            @if (isset($links['company'][$key]))
                                <x-ui.link :href="$links['company'][$key]">{{ $format($metric, $kind) }}</x-ui.link>
                            @else
                                {{ $format($metric, $kind) }}
                            @endif
                        </td>
                        <td class="px-table-cell-x py-table-cell-y text-sm text-muted">{{ $metric['definition'] }}</td>
                        <td class="px-table-cell-x py-table-cell-y text-sm text-muted"><x-ui.datetime :value="$metric['as_of']" format="datetime" /></td>
                    </tr>
                @endforeach
            </x-ui.table>
        </x-ui.card>

        <x-ui.card>
            <div class="flex flex-wrap items-end justify-between gap-4">
                <p class="text-sm text-muted">{{ __('Each department row uses the same definitions; rows add up to the company row for every count.') }}</p>
                @if ($summary->departments !== [])
                    <label class="text-sm text-muted">
                        <span class="sr-only">{{ __('Department') }}</span>
                        <select wire:change="selectDepartment($event.target.value)" class="rounded border-muted text-sm">
                            <option value="" @selected($department === '')>{{ __('All departments') }}</option>
                            @foreach ($summary->departments as $row)
                                @if ($row['department_entity_id'] !== null)
                                    <option value="{{ $row['department_entity_id'] }}" @selected($department === (string) $row['department_entity_id'])>{{ $row['department'] }}</option>
                                @endif
                            @endforeach
                        </select>
                    </label>
                @endif
            </div>

            <x-ui.table container="flush" :caption="__('Skill KPIs by department')">
                <x-slot name="head">
                    <tr>
                        <x-ui.th>{{ __('Department') }}</x-ui.th>
                        @foreach ($metrics as $key => [$label, $definition, $kind])
                            <x-ui.th align="right"><abbr title="{{ $definition }}">{{ __($label) }}</abbr></x-ui.th>
                        @endforeach
                    </tr>
                </x-slot>
                @forelse ($departmentRows as $row)
                    @php($scope = (string) ($row['department_entity_id'] ?? 'none'))
                    <tr wire:key="department-{{ $scope }}" data-kpi-department="{{ $scope }}">
                        <td class="px-table-cell-x py-table-cell-y text-sm font-medium text-ink">{{ $row['department'] }}</td>
                        @foreach ($metrics as $key => [$label, $definition, $kind])
                            @php($metric = $row['metrics'][$key])
                            <td class="px-table-cell-x py-table-cell-y text-right text-sm tabular-nums text-ink" title="{{ $title($metric) }}" data-kpi="{{ $key }}" data-kpi-value="{{ $metric['value'] ?? 'null' }}">
                                @if (isset($links[$scope][$key]))
                                    <x-ui.link :href="$links[$scope][$key]">{{ $format($metric, $kind) }}</x-ui.link>
                                @else
                                    {{ $format($metric, $kind) }}
                                @endif
                            </td>
                        @endforeach
                    </tr>
                @empty
                    <tr>
                        <td colspan="{{ count($metrics) + 1 }}" class="px-table-cell-x py-10 text-center text-sm text-muted">
                            {{ __('No active employees, assessments, actions or events are recorded for this scope.') }}
                        </td>
                    </tr>
                @endforelse
            </x-ui.table>
        </x-ui.card>
    @endif
</div>
