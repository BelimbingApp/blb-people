<div>
    <x-slot name="title">{{ __('People Settings') }}</x-slot>

    <div class="space-y-section-gap">
        <x-ui.page-header :title="__('People Settings')" :subtitle="__('Reference data, employee access, migration imports, and operations controls for People workflows.')">
            <x-slot name="help">
                {{ __('Names here use BLB vocabulary. iPayroll labels are retained only as source metadata for migration and audit.') }}
            </x-slot>
        </x-ui.page-header>

        @if (session('success'))
            <x-ui.alert variant="success">{{ session('success') }}</x-ui.alert>
        @endif

        <x-ui.card>
            <div class="mb-4 flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
                <x-ui.tabs
                    :tabs="$tabs"
                    :default="$tab"
                    size="sm"
                    persistence="none"
                    wire-action="setTab"
                    class="w-full lg:w-auto"
                >
                    @foreach ($tabs as $tabItem)
                        <x-ui.tab :id="$tabItem['id']" />
                    @endforeach
                </x-ui.tabs>

                @if ($tab === 'reference-data')
                    <div class="flex w-full flex-col gap-3 sm:flex-row lg:w-auto lg:items-center">
                        <x-ui.search-input wire:model.live.debounce.300ms="search" placeholder="{{ __('Search code, name, or source label...') }}" class="w-full lg:w-80" />
                        @if ($canManage)
                            <x-ui.button variant="primary" wire:click="$set('showReferenceEntryModal', true)">
                                <x-icon name="heroicon-o-plus" class="w-4 h-4" />
                                {{ __('New Reference') }}
                            </x-ui.button>
                        @endif
                    </div>
                @endif
            </div>

            @if ($tab === 'reference-data')
                <div class="space-y-4">
                    <x-ui.table container="flush" :caption="__('People settings')">

                        <x-slot name="head">
                                <tr>
                                    <x-ui.th>{{ __('Type') }}</x-ui.th>
                                    <x-ui.th>{{ __('Code') }}</x-ui.th>
                                    <x-ui.th>{{ __('Name') }}</x-ui.th>
                                    <x-ui.th>{{ __('Source') }}</x-ui.th>
                                    <x-ui.th>{{ __('Status') }}</x-ui.th>
                                </tr>
                            </x-slot>

                                @forelse ($referenceEntries as $entry)
                                    <tr wire:key="reference-entry-{{ $entry->id }}">
                                        <td class="px-table-cell-x py-table-cell-y whitespace-nowrap">{{ $referenceTypes[$entry->type] ?? $entry->type }}</td>
                                        <td class="px-table-cell-x py-table-cell-y whitespace-nowrap font-mono text-xs">{{ $entry->code }}</td>
                                        <td class="px-table-cell-x py-table-cell-y">
                                            <div class="font-medium text-ink">{{ $entry->name }}</div>
                                            @if ($entry->level)
                                                <div class="text-xs text-muted">{{ __('Level: :level', ['level' => $entry->level]) }}</div>
                                            @endif
                                        </td>
                                        <td class="px-table-cell-x py-table-cell-y text-xs text-muted">{{ $entry->source_label ?? '-' }}</td>
                                        <td class="px-table-cell-x py-table-cell-y whitespace-nowrap"><x-ui.badge>{{ __(ucfirst($entry->status)) }}</x-ui.badge></td>
                                    </tr>
                                @empty
                                    <tr><td colspan="5" class="px-table-cell-x py-8 text-center text-sm text-muted">{{ __('No reference data yet.') }}</td></tr>
                                @endforelse

                    </x-ui.table>

                    {{ $referenceEntries->links() }}
                </div>
            @elseif ($tab === 'portal-access')
                <div class="space-y-3">
                    @forelse ($portalAccesses as $access)
                        <div class="rounded-xl border border-border-default p-4" wire:key="portal-access-{{ $access->id }}">
                            <div class="flex items-start justify-between gap-3">
                                <div>
                                    <div class="font-medium text-ink">{{ $access->display_name }}</div>
                                    <div class="text-xs text-muted">{{ $access->employee?->employee_number }} · {{ $access->login_identifier ?? '-' }} · {{ $access->email ?? '-' }}</div>
                                </div>
                                <x-ui.badge>{{ __(ucfirst($access->status)) }}</x-ui.badge>
                            </div>
                        </div>
                    @empty
                        <p class="text-sm text-muted">{{ __('No employee portal access records yet.') }}</p>
                    @endforelse
                </div>
            @elseif ($tab === 'requests')
                <div class="space-y-3">
                    @forelse ($profileRequests as $request)
                        <div class="rounded-xl border border-border-default p-4" wire:key="profile-request-{{ $request->id }}">
                            <div class="flex items-start justify-between gap-3">
                                <div>
                                    <div class="font-medium text-ink">{{ $request->employee?->displayName() }}</div>
                                    <div class="text-xs text-muted">{{ $request->request_type }} · {{ $request->submitted_at?->format('Y-m-d H:i') }}</div>
                                </div>
                                <x-ui.badge>{{ __(ucfirst($request->status)) }}</x-ui.badge>
                            </div>
                        </div>
                    @empty
                        <p class="text-sm text-muted">{{ __('No profile change requests yet.') }}</p>
                    @endforelse
                </div>
            @elseif ($tab === 'restricted')
                @if (! $canViewSensitive)
                    <x-ui.alert variant="warning">{{ __('Restricted-person records require sensitive People Settings access.') }}</x-ui.alert>
                @else
                    <div class="space-y-3">
                        @forelse ($restrictedEntries as $entry)
                            <div class="rounded-xl border border-border-default p-4" wire:key="restricted-entry-{{ $entry->id }}">
                                <div class="flex items-start justify-between gap-3">
                                    <div>
                                        <div class="font-medium text-ink">{{ $entry->person_name ?? __('Unnamed person') }}</div>
                                        <div class="text-xs text-muted">{{ $entry->document_type ?? '-' }} · {{ $entry->document_number ?? '-' }}</div>
                                    </div>
                                    <x-ui.badge>{{ __(ucfirst($entry->status)) }}</x-ui.badge>
                                </div>
                            </div>
                        @empty
                            <p class="text-sm text-muted">{{ __('No restricted-person entries yet.') }}</p>
                        @endforelse
                    </div>
                @endif
            @elseif ($tab === 'imports')
                <div class="space-y-3">
                    @if ($canManage)
                        <x-ui.button variant="secondary" wire:click="dryRunSampleImport">{{ __('Record empty dry-run import') }}</x-ui.button>
                    @endif
                    @forelse ($importJobs as $job)
                        <div class="rounded-xl border border-border-default p-4" wire:key="import-job-{{ $job->id }}">
                            <div class="flex items-start justify-between gap-3">
                                <div>
                                    <div class="font-medium text-ink">{{ $job->source_label }} → {{ $referenceTypes[$job->target_type] ?? $job->target_type }}</div>
                                    <div class="text-xs text-muted">{{ __('Rows: :rows, errors: :errors', ['rows' => $job->summary['total_rows'] ?? 0, 'errors' => $job->summary['error_rows'] ?? 0]) }}</div>
                                </div>
                                <x-ui.badge>{{ __(ucfirst($job->status)) }}</x-ui.badge>
                            </div>
                        </div>
                    @empty
                        <p class="text-sm text-muted">{{ __('No import jobs yet.') }}</p>
                    @endforelse
                </div>
            @else
                <div class="space-y-3">
                    @forelse ($notificationLogs as $log)
                        <div class="rounded-xl border border-border-default p-4" wire:key="notification-log-{{ $log->id }}">
                            <div class="flex items-start justify-between gap-3">
                                <div>
                                    <div class="font-medium text-ink">{{ $log->subject ?? __('Notification') }}</div>
                                    <div class="text-xs text-muted">{{ $log->channel }} · {{ $log->recipient ?? '-' }}</div>
                                </div>
                                <x-ui.badge>{{ __(ucfirst($log->status)) }}</x-ui.badge>
                            </div>
                        </div>
                    @empty
                        <p class="text-sm text-muted">{{ __('No notification delivery logs yet.') }}</p>
                    @endforelse
                </div>
            @endif
        </x-ui.card>
    </div>

    @if ($tab === 'reference-data' && $canManage)
        <x-ui.modal wire:model="showReferenceEntryModal" class="max-w-2xl">
            <form wire:submit="createReferenceEntry" class="p-6 space-y-4">
                <div class="flex items-start justify-between gap-3">
                    <div>
                        <h3 class="text-lg font-medium tracking-tight text-ink">{{ __('New Reference') }}</h3>
                        <p class="mt-1 text-sm text-muted">{{ __('Create or update People reference data by type and code.') }}</p>
                    </div>
                    <button type="button" @click="show = false" class="text-muted hover:text-ink" aria-label="{{ __('Close') }}">
                        <x-icon name="heroicon-o-x-mark" class="w-5 h-5" />
                    </button>
                </div>

                <x-ui.select id="people-reference-type" wire:model="referenceType" label="{{ __('Type') }}">
                    @foreach ($referenceTypes as $type => $label)
                        <option value="{{ $type }}">{{ __($label) }}</option>
                    @endforeach
                </x-ui.select>

                <div class="grid gap-4 md:grid-cols-2">
                    <x-ui.input id="people-reference-code" wire:model="entryCode" label="{{ __('Code') }}" required :error="$errors->first('entryCode')" />
                    <x-ui.input id="people-reference-level" wire:model="entryLevel" label="{{ __('Level') }}" placeholder="{{ __('Optional') }}" :error="$errors->first('entryLevel')" />
                </div>

                <x-ui.input id="people-reference-name" wire:model="entryName" label="{{ __('Name') }}" required :error="$errors->first('entryName')" />
                <x-ui.input id="people-reference-source-label" wire:model="entrySourceLabel" label="{{ __('Source label') }}" placeholder="{{ __('Optional migration/source label') }}" :error="$errors->first('entrySourceLabel')" />

                <div class="flex justify-end gap-3 pt-2">
                    <x-ui.button type="button" variant="secondary" @click="show = false">{{ __('Cancel') }}</x-ui.button>
                    <x-ui.button type="submit" variant="primary">{{ __('Save Reference') }}</x-ui.button>
                </div>
            </form>
        </x-ui.modal>
    @endif
</div>
