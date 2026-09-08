<div class="space-y-section-gap">
    <x-slot name="title">{{ __('Training requests register') }}</x-slot>

    <x-ui.page-header
        :title="__('Training requests register')"
        :subtitle="__('Every training request of the company for :year. Search, filter, and export the same company-scoped register; decisions stay in HR governance.', ['year' => $year])"
    >
        @if ($canUseGovernance)
            <x-slot name="actions">
                <x-ui.link kind="internal" href="{{ route('people.hr-governance.index') }}" wire:navigate>
                    {{ __('Open HR governance') }}
                </x-ui.link>
            </x-slot>
        @endif
    </x-ui.page-header>

    @if ($companies === [])
        <x-ui.alert variant="info">{{ __('No company is attributed to your HR role.') }}</x-ui.alert>
    @else
        @if (count($companies) > 1)
            <fieldset class="flex flex-wrap gap-2 text-sm">
                <legend class="sr-only">{{ __('Company') }}</legend>
                @foreach ($companies as $entityId => $name)
                    <x-ui.button type="button" wire:click="selectCompany({{ $entityId }})" :variant="$companyEntityId === $entityId ? 'primary' : 'secondary'">
                        {{ $name }}
                    </x-ui.button>
                @endforeach
            </fieldset>
        @endif

        <x-ui.card>
            <x-ui.filter-bar class="mb-3">
                <x-slot name="search">
                    <x-ui.search-input
                        id="training-requests-search"
                        wire:key="training-requests-search"
                        wire:model.live.debounce.300ms="search"
                        :placeholder="__('Search need, employee, department, or status...')"
                    />
                </x-slot>

                <x-ui.select id="training-requests-status" wire:model.live="status" :label="__('Status')">
                    <option value="">{{ __('All statuses') }}</option>
                    <option value="approved_unlinked">{{ __('Approved, not linked to an event') }}</option>
                    @foreach ($statuses as $case)
                        <option value="{{ $case->value }}">{{ $case->label() }}</option>
                    @endforeach
                </x-ui.select>

                <x-ui.select id="training-requests-department" wire:model.live="department" :label="__('Department')">
                    <option value="">{{ __('All departments') }}</option>
                    @foreach ($departments as $unitId => $name)
                        <option value="{{ $unitId }}">{{ $name }}</option>
                    @endforeach
                </x-ui.select>

                <div class="flex items-end">
                    <x-ui.button type="button" variant="secondary" wire:click="export">
                        {{ trans_choice('Export :count request to CSV|Export :count requests to CSV', $rows->total(), ['count' => $rows->total()]) }}
                    </x-ui.button>
                </div>
            </x-ui.filter-bar>

            <x-ui.table
                container="flush"
                :caption="__('Training requests register')"
                :empty="$rows->isEmpty()"
                :empty-colspan="8"
                :empty-message="$search !== '' || $status !== '' || $department !== '' ? __('No training requests match the current filters.') : __('No training requests were recorded for :year.', ['year' => $year])"
            >
                <x-slot:head>
                    <tr>
                        <x-ui.sortable-th column="created_at" :sort-by="$sortBy" :sort-dir="$sortDir" action="sort('created_at')" :label="__('Created')" nowrap />
                        <x-ui.sortable-th column="need" :sort-by="$sortBy" :sort-dir="$sortDir" action="sort('need')" :label="__('Request')" />
                        <x-ui.th align="right">{{ __('People') }}</x-ui.th>
                        <x-ui.th>{{ __('Department') }}</x-ui.th>
                        <x-ui.sortable-th column="status" :sort-by="$sortBy" :sort-dir="$sortDir" action="sort('status')" :label="__('Status')" nowrap />
                        <x-ui.sortable-th column="estimated_cost" :sort-by="$sortBy" :sort-dir="$sortDir" action="sort('estimated_cost')" :label="$currencyCode === null ? __('Estimated cost (currency unavailable)') : __('Estimated cost (:currency)', ['currency' => $currencyCode])" numeric nowrap />
                        <x-ui.th>{{ __('Decision / event') }}</x-ui.th>
                        <x-ui.th align="right">{{ __('Actions') }}</x-ui.th>
                    </tr>
                </x-slot:head>

                <x-slot:body>
                    @foreach ($rows as $row)
                        @php
                            $statusVariant = match ($row['status']) {
                                'approved' => 'success',
                                'rejected', 'cancelled' => 'danger',
                                'pending_hod', 'pending_hr', 'pending_approval' => 'warning',
                                default => 'default',
                            };
                        @endphp
                        <tr wire:key="training-request-row-{{ $row['id'] }}">
                            <td class="px-table-cell-x py-table-cell-y whitespace-nowrap text-sm text-muted tabular-nums">
                                <x-ui.datetime :value="$row['created_at_value']" format="date" />
                            </td>
                            <td class="px-table-cell-x py-table-cell-y text-sm text-ink">
                                <span class="font-medium">{{ $row['need'] }}</span>
                                <span class="block text-muted">{{ $row['requestor'] }} · {{ $row['priority_label'] }}</span>
                            </td>
                            <td class="px-table-cell-x py-table-cell-y text-right text-sm tabular-nums text-ink">{{ $row['subjects'] }}</td>
                            <td class="px-table-cell-x py-table-cell-y text-sm text-ink">{{ $row['department'] }}</td>
                            <td class="px-table-cell-x py-table-cell-y whitespace-nowrap text-sm text-ink">
                                <x-ui.badge :variant="$statusVariant" data-status="{{ $row['status'] }}">{{ $row['status_label'] }}</x-ui.badge>
                            </td>
                            <td class="px-table-cell-x py-table-cell-y whitespace-nowrap text-right text-sm tabular-nums text-ink">
                                {{ $row['estimated_cost_display'] ?? '—' }}
                            </td>
                            <td class="px-table-cell-x py-table-cell-y text-sm text-ink">
                                @if ($row['approver'] === '' && $row['linked_event_title'] === '' && $row['linked_event_id'] === '')
                                    <span class="text-muted">—</span>
                                @else
                                    @if ($row['approver'] !== '')
                                        <span>{{ $row['approver'] }}</span>
                                        <span class="block text-muted tabular-nums"><x-ui.datetime :value="$row['decided_at_value']" format="date" /></span>
                                    @endif
                                    @if ($row['linked_event_title'] !== '' || $row['linked_event_id'] !== '')
                                        <span class="block text-muted">{{ $row['linked_event_title'] !== '' ? $row['linked_event_title'] : __('Event :id', ['id' => $row['linked_event_id']]) }}</span>
                                    @endif
                                @endif
                            </td>
                            <td class="px-table-cell-x py-table-cell-y whitespace-nowrap text-right">
                                <x-ui.icon-action icon="heroicon-o-eye" :label="__('View request details')" wire:click="openDetails({{ $row['id'] }})" />
                            </td>
                        </tr>
                    @endforeach
                </x-slot:body>
            </x-ui.table>

            <div class="mt-3">
                <x-ui.pagination
                    :paginator="$rows"
                    :per-page-options="$this->perPageOptions()"
                    :per-page="$perPage"
                    id="training-requests-per-page"
                />
            </div>
        </x-ui.card>

        @if ($selectedRequest !== null)
            <x-ui.inspector-drawer
                wire:model="selectedRequestId"
                close-action="closeDetails"
                labelledby="training-request-details-title"
                storage-key="blb:people:training-request-details:width"
            >
                <div class="flex h-full flex-col">
                    <header class="flex items-start justify-between gap-4 border-b border-border-default p-card-inner">
                        <div>
                            <h2 id="training-request-details-title" class="text-lg font-medium tracking-tight text-ink">{{ $selectedRequest['need'] }}</h2>
                            <p class="mt-1 text-sm text-muted">{{ __('Requested by :employee for :department', ['employee' => $selectedRequest['requestor'], 'department' => $selectedRequest['department']]) }}</p>
                        </div>
                        <x-ui.icon-action icon="heroicon-o-x-mark" :label="__('Close request details')" wire:click="closeDetails" />
                    </header>

                    <div class="flex-1 space-y-section-gap overflow-y-auto p-card-inner">
                        <section>
                            <h3 class="text-[11px] uppercase tracking-wider font-semibold text-muted">{{ __('Request') }}</h3>
                            <dl class="mt-3 grid gap-4 text-sm sm:grid-cols-2">
                                <div><dt class="text-muted">{{ __('Status') }}</dt><dd class="mt-1 text-ink">{{ $selectedRequest['status_label'] }}</dd></div>
                                <div><dt class="text-muted">{{ __('Priority') }}</dt><dd class="mt-1 text-ink">{{ $selectedRequest['priority_label'] }}</dd></div>
                                <div><dt class="text-muted">{{ __('Created') }}</dt><dd class="mt-1 text-ink tabular-nums"><x-ui.datetime :value="$selectedRequest['created_at_value']" format="date" /></dd></div>
                                <div><dt class="text-muted">{{ __('Estimated cost') }}</dt><dd class="mt-1 text-ink tabular-nums">{{ $selectedRequest['estimated_cost_display'] ?? '—' }}</dd></div>
                                @if ($selectedRequest['linked_event_title'] !== '' || $selectedRequest['linked_event_id'] !== '')
                                    <div class="sm:col-span-2"><dt class="text-muted">{{ __('Linked event') }}</dt><dd class="mt-1 text-ink">{{ $selectedRequest['linked_event_title'] !== '' ? $selectedRequest['linked_event_title'] : __('Event :id', ['id' => $selectedRequest['linked_event_id']]) }}</dd></div>
                                @endif
                                <div class="sm:col-span-2"><dt class="text-muted">{{ __('Learning objective') }}</dt><dd class="mt-1 text-ink">{{ $selectedRequest['learning_objective'] }}</dd></div>
                                <div class="sm:col-span-2"><dt class="text-muted">{{ __('Expected result') }}</dt><dd class="mt-1 text-ink">{{ $selectedRequest['expected_result'] }}</dd></div>
                            </dl>
                        </section>

                        <section>
                            <h3 class="text-[11px] uppercase tracking-wider font-semibold text-muted">{{ __('Decision history') }}</h3>
                            @if ($selectedRequest['decisions']->isEmpty())
                                <p class="mt-3 text-sm text-muted">{{ __('No decision has been recorded yet.') }}</p>
                            @else
                                <ol class="mt-3 space-y-3">
                                    @foreach ($selectedRequest['decisions'] as $decision)
                                        <li class="border-b border-border-default pb-3 text-sm last:border-0 last:pb-0">
                                            <div class="flex flex-wrap items-baseline justify-between gap-2">
                                                <span class="font-medium text-ink">{{ $decision['label'] }}</span>
                                                <span class="text-muted tabular-nums"><x-ui.datetime :value="$decision['occurred_at']" /></span>
                                            </div>
                                            <p class="mt-1 text-muted">{{ $decision['actor'] }}</p>
                                            @if ($decision['notes'] !== '')
                                                <p class="mt-1 text-ink">{{ $decision['notes'] }}</p>
                                            @endif
                                        </li>
                                    @endforeach
                                </ol>
                            @endif
                        </section>

                        @if ($canUseGovernance)
                            <x-ui.link kind="internal" href="{{ route('people.hr-governance.index') }}" wire:navigate>
                                {{ __('Continue in HR governance') }}
                            </x-ui.link>
                        @endif
                    </div>
                </div>
            </x-ui.inspector-drawer>
        @endif
    @endif
</div>
