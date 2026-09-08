<div
    class="space-y-section-gap"
    x-data="{
        syncAsOfText() {
            this.$el.querySelectorAll('[data-kpi-title]').forEach((cell) => {
                const description = cell.querySelector('[data-kpi-title-description]');

                if (description) {
                    cell.title = description.textContent.trim();
                }
            });

            const summary = this.$el.querySelector('[data-kpi-summary-message]');
            const moment = this.$el.querySelector('[data-kpi-summary-moment]');

            if (summary && moment) {
                const message = summary.dataset.template.replace('__BLB_MOMENT__', moment.textContent.trim());

                if (summary.textContent !== message) {
                    summary.textContent = message;
                }
            }
        },
    }"
    x-init="
        const observer = new MutationObserver(() => syncAsOfText());
        observer.observe($el, { childList: true, characterData: true, subtree: true });
        syncAsOfText();
        $cleanup(() => observer.disconnect());
    "
>
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
            $dateTimes = app(\App\Base\DateTime\Contracts\DateTimeDisplayService::class);
            $format = static fn (array $metric, string $kind): string => $kind === 'rate'
                ? ($metric['value'] === null ? __('n/a') : number_format($metric['value'] * 100, 1).'%')
                : (string) $metric['value'];
            $title = static fn (array $metric): string => $metric['definition'].' '.__('As of :moment.', [
                'moment' => $dateTimes->formatDateTime($metric['as_of']),
            ]);
            $machineAsOf = static fn (\DateTimeInterface $value): string => \Carbon\CarbonImmutable::instance($value)
                ->utc()
                ->toIso8601String();
        @endphp

        <x-ui.card>
            <p class="text-sm text-muted" data-kpi-as-of="{{ $machineAsOf($summary->asOf) }}">
                <span
                    data-kpi-summary-message
                    data-template="{{ __('As of :moment. Rates read n/a when nothing was expected or recorded.', ['moment' => '__BLB_MOMENT__']) }}"
                >{{ __('As of :moment. Rates read n/a when nothing was expected or recorded.', ['moment' => $dateTimes->formatDateTime($summary->asOf)]) }}</span>
                <span class="sr-only" aria-hidden="true" data-kpi-summary-moment><x-ui.datetime :value="$summary->asOf" format="datetime" /></span>
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
                    @php
                        $metric = $summary->company[$key];
                        $descriptionId = 'company-kpi-'.$key.'-description';
                    @endphp
                    <tr wire:key="company-{{ $key }}" data-kpi="{{ $key }}" data-kpi-value="{{ $metric['value'] ?? 'null' }}">
                        <td class="px-table-cell-x py-table-cell-y text-sm font-medium text-ink">{{ __($label) }}</td>
                        <td
                            class="px-table-cell-x py-table-cell-y text-right text-sm tabular-nums text-ink"
                            title="{{ $title($metric) }}"
                            aria-describedby="{{ $descriptionId }}"
                            data-kpi-title
                        >
                            <span id="{{ $descriptionId }}" class="sr-only" data-kpi-title-description>
                                {{ $metric['definition'] }} {{ __('As of') }} <x-ui.datetime :value="$metric['as_of']" format="datetime" />.
                            </span>
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
                    @php
                        $scope = (string) ($row['department_entity_id'] ?? 'none');
                    @endphp
                    <tr wire:key="department-{{ $scope }}" data-kpi-department="{{ $scope }}">
                        <td class="px-table-cell-x py-table-cell-y text-sm font-medium text-ink">{{ $row['department'] }}</td>
                        @foreach ($metrics as $key => [$label, $definition, $kind])
                            @php
                                $metric = $row['metrics'][$key];
                                $descriptionId = 'department-'.$scope.'-kpi-'.$key.'-description';
                            @endphp
                            <td
                                class="px-table-cell-x py-table-cell-y text-right text-sm tabular-nums text-ink"
                                title="{{ $title($metric) }}"
                                aria-describedby="{{ $descriptionId }}"
                                data-kpi-title
                                data-kpi="{{ $key }}"
                                data-kpi-value="{{ $metric['value'] ?? 'null' }}"
                            >
                                <span id="{{ $descriptionId }}" class="sr-only" data-kpi-title-description>
                                    {{ $metric['definition'] }} {{ __('As of') }} <x-ui.datetime :value="$metric['as_of']" format="datetime" />.
                                </span>
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
