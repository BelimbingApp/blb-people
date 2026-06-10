@use('App\Modules\People\Attendance\Support\DayTypeVocabulary')
@php
    $compact = $compact ?? false;
    $gridIntro = $gridIntro ?? __('Assigned cells reflect the current roster; rest, off, and holiday days surface from each employee\'s work calendar.');
    $cellMinWidth = $compact ? 'min-w-9' : 'min-w-14';
    $showDayDrawer = $showDayDrawer ?? true;
    $lockedDates = $lockedDates ?? [];
    $actualOutcomes = $actualOutcomes ?? [];
    $actualMode = $actualMode ?? false;
    $dayDrawerData = [];
    if ($showDayDrawer && !empty($rosterGridRows)) {
        foreach ($rosterGridDays as $day) {
            $entries = [];
            foreach ($rosterGridRows as $row) {
                $cell = $row['cells'][$day['date']] ?? null;
                if (! $cell) {
                    continue;
                }
                $entries[] = [
                    'name'    => $row['employee']->displayName(),
                    'shift'   => $cell['label'] ?? '',
                    'state'   => $cell['state'] ?? 'empty',
                    'dayType' => $cell['day_type'] ?? 'normal',
                    'title'   => $cell['title'] ?? '',
                    'empty'   => ($cell['state'] ?? 'empty') === 'empty',
                ];
            }
            $dayDrawerData[$day['date']] = [
                'label'     => \Carbon\CarbonImmutable::parse($day['date'])->format('j M, D'),
                'isHoliday' => $day['is_holiday'] ?? false,
                'isWeekend' => $day['is_weekend'] ?? false,
                'entries'   => $entries,
                'assigned'  => count(array_filter($entries, fn ($e) => ! $e['empty'])),
            ];
        }
    }
@endphp

<style>
.roster-selected { outline: 2px solid var(--color-accent, #6366f1); outline-offset: 0; }
.roster-fill-preview { background-color: color-mix(in srgb, var(--color-accent, #6366f1) 12%, transparent); }
.roster-copied { outline: 2px dashed var(--color-accent, #6366f1); outline-offset: -2px; }
.roster-fill-handle { opacity: 0; pointer-events: none; transition: opacity 100ms; }
td:hover .roster-fill-handle, .roster-fill-handle.roster-handle-visible { opacity: 1; pointer-events: auto; }

@media print {
    @page { size: A4 landscape; margin: 10mm; }
    /* Hide layout chrome */
    body > div > div > div:first-child,
    body > div > div > div:last-child { display: none !important; }
    main { overflow: visible !important; padding: 0 !important; }
    /* Hide non-roster UI within the page */
    .roster-print-hide { display: none !important; }
    /* Remove backgrounds; use borders for photocopier safety */
    .roster-grid-print table td,
    .roster-grid-print table th {
        background: white !important;
        border: 1px solid #000 !important;
        font-size: 10pt !important;
    }
    .roster-grid-print .roster-grid-cell-label { font-size: 10pt !important; font-weight: 600; }
    .roster-grid-print table { border-collapse: collapse !important; width: 100% !important; }
}
</style>

<div x-data="rosterGrid(@js($dayDrawerData ?? []))"
     @show-day-drawer.window="openDrawer($event.detail.date)"
     @grid-cell-select.window="handleCellSelect($event.detail)"
     @keydown.window="handleKeydown($event)"
     @open-swap-modal.window="openSwapModal($event.detail.empId)"
     @open-bulk-assign-modal.window="openBulkModal($event.detail.empIds)"
     @close-swap-modal.window="swapModalOpen = false"
     @close-bulk-modal.window="bulkModalOpen = false"
     class="relative">

<div class="roster-print-hide flex flex-col gap-3 lg:flex-row lg:items-start lg:justify-between">
    @if ($gridIntro)
        <p class="text-sm text-muted">{{ $gridIntro }}</p>
    @else
        <div></div>
    @endif
    <div class="flex flex-wrap items-center gap-2">
        <span class="inline-flex items-center rounded-full bg-accent/10 px-2.5 py-0.5 text-xs font-medium text-accent">{{ __('Assigned') }}</span>
        <span class="text-[11px] text-muted">·</span>
        <span class="inline-flex items-center gap-1 rounded-full bg-day-rest px-2 py-0.5 text-[11px] font-medium text-day-rest-ink">{{ __('Rest') }}</span>
        <span class="inline-flex items-center gap-1 rounded-full bg-day-off px-2 py-0.5 text-[11px] font-medium text-day-off-ink">{{ __('Off') }}</span>
        <span class="inline-flex items-center gap-1 rounded-full bg-day-holiday px-2 py-0.5 text-[11px] font-medium text-day-holiday-ink">{{ __('Holiday') }}</span>
    </div>
</div>

{{-- Combined action bar --}}
@if ($canManage)
<div class="roster-print-hide mt-3 flex items-center gap-1 overflow-x-auto rounded-xl border border-border-default bg-surface-card px-2 py-1.5 text-xs">

    {{-- Default: nothing selected --}}
    <span x-show="selectedRows.length === 0 && selection.length === 0"
          class="shrink-0 text-muted/50">{{ __('Click an employee name to select rows, or a cell to edit') }}</span>

    {{-- Row mode --}}
    <div x-show="selectedRows.length > 0" class="flex shrink-0 items-center gap-1">
        <span class="shrink-0 font-medium text-ink">
            <span x-text="selectedRows.length"></span>&nbsp;{{ __('row(s)') }}
        </span>
        <button type="button" @click="clearSelection()"
                class="flex items-center gap-1 rounded-md px-2 py-1 text-muted hover:bg-surface-subtle hover:text-ink focus:outline-none focus:ring-1 focus:ring-accent">
            <x-icon name="heroicon-o-x-circle" class="h-3.5 w-3.5" />{{ __('Clear') }}
        </button>
        <div class="mx-0.5 h-4 w-px shrink-0 bg-border-default"></div>
        <button type="button"
                :disabled="selectedRows.length !== 1"
                @click="$dispatch('open-swap-modal', { empId: selectedRows[0] })"
                class="flex items-center gap-1 rounded-md px-2 py-1 text-muted hover:bg-surface-subtle hover:text-ink focus:outline-none focus:ring-1 focus:ring-accent disabled:cursor-not-allowed disabled:opacity-40">
            <x-icon name="heroicon-o-arrows-right-left" class="h-3.5 w-3.5" />{{ __('Swap') }}
        </button>
        <button type="button"
                @click="$dispatch('open-bulk-assign-modal', { empIds: selectedRows })"
                class="flex items-center gap-1 rounded-md border border-border-default px-2.5 py-1 font-medium text-ink hover:bg-surface-subtle focus:outline-none focus:ring-1 focus:ring-accent">
            <x-icon name="heroicon-o-calendar-days" class="h-3.5 w-3.5" />{{ __('Bulk assign') }}
        </button>
    </div>

    {{-- Cell mode --}}
    <div x-show="selection.length > 0" class="flex shrink-0 items-center gap-1">
        <div class="flex min-w-[6rem] shrink-0 items-center rounded-md bg-surface-subtle px-2 py-0.5">
            <template x-if="barCount === 1"><span class="max-w-[12rem] truncate font-medium text-ink" x-text="barAddress"></span></template>
            <template x-if="barCount > 1"><span class="font-medium text-ink" x-text="barCount + ' {{ __('cells') }}'"></span></template>
        </div>
        <select :value="barShift" @change="barShift = parseInt($event.target.value) || 0; saveBar()"
                class="min-w-0 w-36 rounded-lg border border-border-default bg-surface-card px-2 py-1 text-xs text-ink focus:outline-none focus:ring-2 focus:ring-accent">
            <option value="0">{{ __('Shift') }}</option>
            @foreach ($shiftTemplates as $shiftTpl)
                <option value="{{ $shiftTpl->id }}">{{ $shiftTpl->code }} — {{ $shiftTpl->name }}</option>
            @endforeach
        </select>
        <select :value="barPolicy" @change="barPolicy = parseInt($event.target.value) || 0; saveBar()"
                class="min-w-0 w-36 rounded-lg border border-border-default bg-surface-card px-2 py-1 text-xs text-ink focus:outline-none focus:ring-2 focus:ring-accent">
            <option value="0">{{ __('Policy') }}</option>
            @foreach ($policyGroups as $policyGrp)
                <option value="{{ $policyGrp->id }}">{{ $policyGrp->code }} — {{ $policyGrp->name }}</option>
            @endforeach
        </select>
        <input type="text" x-model="barJob" @keydown.enter="saveBar()"
               placeholder="{{ __('Job') }}"
               class="w-20 rounded-lg border border-border-default bg-surface-card px-2 py-1 text-xs text-ink placeholder-muted focus:outline-none focus:ring-2 focus:ring-accent" />
        <input type="text" x-model="barNote" @keydown.enter="saveBar()"
               placeholder="{{ __('Note') }}"
               class="w-32 rounded-lg border border-border-default bg-surface-card px-2 py-1 text-xs text-ink placeholder-muted focus:outline-none focus:ring-2 focus:ring-accent" />
        <div class="mx-0.5 h-4 w-px shrink-0 bg-border-default"></div>
        <button type="button" @click="clearSelection()"
                class="flex items-center gap-1 rounded-md px-2 py-1 text-muted hover:bg-surface-subtle hover:text-ink focus:outline-none focus:ring-1 focus:ring-accent">
            <x-icon name="heroicon-o-x-circle" class="h-3.5 w-3.5" />{{ __('Clear') }}
        </button>
        <template x-if="barCount === 1">
            <button type="button" @click="openHistory()"
                    class="flex items-center gap-1 rounded-md border border-border-default px-2.5 py-1 font-medium text-muted hover:bg-surface-subtle hover:text-ink focus:outline-none focus:ring-1 focus:ring-accent">
                <x-icon name="heroicon-o-clock" class="h-3.5 w-3.5" />{{ __('History') }}
            </button>
        </template>
    </div>

    {{-- Stable right: All · Copy prev · Undo --}}
    <div class="ml-auto flex shrink-0 items-center gap-0.5">
        <div class="mx-1 h-4 w-px shrink-0 bg-border-default"></div>
        <button type="button" @click="selectAllRows()"
                class="flex items-center gap-1 rounded-md px-2 py-1 text-muted hover:bg-surface-subtle hover:text-ink focus:outline-none focus:ring-1 focus:ring-accent">
            <x-icon name="heroicon-o-check-circle" class="h-3.5 w-3.5" />{{ __('All') }}
        </button>
        <div class="mx-0.5 h-4 w-px shrink-0 bg-border-default"></div>
        <button type="button" @click="toolbarCopyPrevious()"
                class="flex items-center gap-1 rounded-md px-2 py-1 text-muted hover:bg-surface-subtle hover:text-ink focus:outline-none focus:ring-1 focus:ring-accent">
            <x-icon name="heroicon-o-document-duplicate" class="h-3.5 w-3.5" />{{ __('Copy prev') }}
        </button>
        <button type="button" @click="$wire.undoLastDraftRosterOperation()"
                :disabled="!$wire.lastDraftAssignmentIds || $wire.lastDraftAssignmentIds.length === 0"
                class="flex items-center gap-1 rounded-md px-2 py-1 text-muted hover:bg-surface-subtle hover:text-ink focus:outline-none focus:ring-1 focus:ring-accent disabled:cursor-not-allowed disabled:opacity-40">
            <x-icon name="heroicon-o-arrow-uturn-left" class="h-3.5 w-3.5" />{{ __('Undo') }}
        </button>
    </div>
</div>
@endif

{{-- Cell history drawer --}}
@if ($canManage)
<div
    x-show="$wire.cellHistoryOpen"
    x-cloak
    x-transition:enter="transition ease-out duration-150"
    x-transition:enter-start="opacity-0 translate-x-4"
    x-transition:enter-end="opacity-100 translate-x-0"
    x-transition:leave="transition ease-in duration-100"
    x-transition:leave-start="opacity-100 translate-x-0"
    x-transition:leave-end="opacity-0 translate-x-4"
    class="absolute right-0 top-10 z-30 w-80 rounded-2xl border border-border-default bg-surface-card shadow-lg"
>
    <div class="flex items-start justify-between gap-2 border-b border-border-default px-4 py-3">
        <div>
            <div class="text-sm font-semibold text-ink">{{ __('Change history') }}</div>
            <div class="text-xs text-muted" wire:stream="cellHistoryContext">
                <span x-text="$wire.cellHistoryEmployeeName"></span>
                <span class="mx-1">·</span>
                <span x-text="$wire.cellHistoryDate"></span>
            </div>
        </div>
        <button type="button" wire:click="closeCellHistory()"
                class="mt-0.5 rounded-md text-muted hover:text-ink focus:outline-none focus:ring-2 focus:ring-accent"
                aria-label="{{ __('Close') }}">
            <x-icon name="heroicon-o-x-mark" class="h-4 w-4" />
        </button>
    </div>

    <div class="max-h-96 overflow-y-auto px-4 py-2">
        <template x-if="$wire.cellHistoryRows.length === 0">
            <p class="py-6 text-center text-sm text-muted">{{ __('No history yet for this cell.') }}</p>
        </template>
        <template x-for="(row, i) in $wire.cellHistoryRows" :key="row.id">
            <div class="border-b border-border-default/50 py-2.5 last:border-0">
                <div class="flex items-center justify-between gap-2">
                    <span class="text-[11px] text-muted" x-text="row.changed_at"></span>
                    <span class="rounded-full px-2 py-0.5 text-[10px] font-semibold"
                          :class="{
                              'bg-status-success/10 text-status-success': row.action === 'created',
                              'bg-danger/10 text-danger': row.action === 'deleted',
                              'bg-warning/10 text-warning': row.action === 'locked',
                              'bg-surface-subtle text-muted': row.action === 'updated',
                          }"
                          x-text="row.action"></span>
                </div>
                <div class="mt-0.5 text-xs font-medium text-ink" x-text="row.changed_by"></div>
                <div class="mt-1 flex items-center gap-1.5 text-xs text-muted">
                    <template x-if="row.prev_shift">
                        <span x-text="(row.prev_shift ?? '—') + ' / ' + (row.prev_policy ?? '—')"></span>
                    </template>
                    <template x-if="row.prev_shift">
                        <x-icon name="heroicon-o-arrow-right" class="h-3 w-3 shrink-0 text-muted" />
                    </template>
                    <span class="font-medium text-ink"
                          x-text="(row.new_shift ?? '—') + ' / ' + (row.new_policy ?? '—')"></span>
                </div>
                <template x-if="row.note || row.job">
                    <div class="mt-1 text-[11px] text-muted">
                        <template x-if="row.job">
                            <span class="mr-1 rounded bg-surface-subtle px-1 py-0.5 text-[10px] font-medium text-ink" x-text="row.job"></span>
                        </template>
                        <span x-text="row.note ?? ''"></span>
                    </div>
                </template>
            </div>
        </template>
    </div>

    <div class="border-t border-border-default px-4 py-2.5">
        <a :href="`{{ route('people.attendance.roster.employee-history') }}?employee_id=${$wire.cellHistoryEmployeeId}`"
           target="_blank"
           class="text-xs font-medium text-accent hover:underline">
            {{ __('Open full history') }} →
        </a>
    </div>
</div>
@endif

<div class="roster-grid-print mt-4 overflow-x-auto rounded-2xl border border-border-default">
    <table class="min-w-full divide-y divide-border-default text-xs">
        <x-people-attendance::day-strip :days="$rosterGridDays" :leading-label="__('Employee')" :compact="$compact" :clickable="$showDayDrawer" />
        <tbody class="divide-y divide-border-default bg-surface-card">
            @forelse ($rosterGridRows as $row)
                @php($employee = $row['employee'])
                @if ($loop->first || $row['group'] !== $rosterGridRows[$loop->index - 1]['group'])
                    <tr wire:key="roster-grid-group-{{ $loop->index }}">
                        <td colspan="{{ count($rosterGridDays) + 1 }}" class="sticky left-0 z-10 bg-surface-subtle px-table-cell-x py-1 text-[11px] font-semibold uppercase tracking-wide text-muted">
                            {{ $row['group'] }}
                        </td>
                    </tr>
                @endif
                <tr wire:key="roster-grid-row-{{ $employee->id }}" data-row-employee="{{ $employee->id }}" class="group hover:bg-surface-subtle/50">
                    <td class="sticky left-0 z-10 w-40 min-w-40 bg-surface-card group-hover:bg-surface-subtle px-table-cell-x py-1.5 align-top{{ $canManage ? ' cursor-pointer select-none' : '' }}"
                        data-name-cell="{{ $employee->id }}"
                        :style="selectedRows.includes('{{ $employee->id }}') ? 'background-image:linear-gradient(to right,var(--color-accent) 3px,transparent 3px)' : ''"
                        @if ($canManage)
                        @click="handleRowSelect($event, '{{ $employee->id }}')"
                        @endif
                    >
                        <div class="truncate text-sm font-medium text-ink" title="{{ $employee->full_name }}">{{ $employee->displayName() }}</div>
                    </td>
                    @foreach ($rosterGridDays as $day)
                        @php($cell = $row['cells'][$day['date']])
                        @php($dayType = $cell['day_type'] ?? 'normal')
                        @php($dayTypeInk = DayTypeVocabulary::inkClass($dayType))
                        @php($isEmpty = $cell['state'] === 'empty')
                        @php($cellShiftId = (int) ($cell['shift_template_id'] ?? 0))
                        @php($cellPolicyId = (int) ($cell['policy_group_id'] ?? 0))
                        @php($isDateLocked = isset($lockedDates[$day['date']]))
                        @php($isToday = $day['is_today'] ?? false)
                        @php($actualOutcome = $actualMode ? ($actualOutcomes[$employee->id . '-' . $day['date']] ?? null) : null)
                        @php($actualTint = match($actualOutcome) { 'absent' => 'ring-2 ring-red-400 ring-inset', 'late', 'early' => 'ring-2 ring-warning ring-inset', 'matched' => 'ring-2 ring-success ring-inset', default => '' })
                        @php($isEditable = $canManage && ! $isDateLocked && ! $actualMode)
                        <td wire:key="roster-grid-cell-{{ $employee->id }}-{{ $day['date'] }}"
                            class="relative p-0 align-middle {{ $actualTint }}{{ $isToday ? ' bg-accent/5' : '' }}"
                            data-employee="{{ $employee->id }}"
                            data-date="{{ $day['date'] }}"
                            data-shift="{{ $cellShiftId }}"
                            data-policy="{{ $cellPolicyId }}"
                            data-state="{{ $cell['state'] }}"
                            @if ($canManage)
                                @click="$dispatch('grid-cell-select', { empId: {{ $employee->id }}, date: '{{ $day['date'] }}', extendShift: $event.shiftKey, toggle: $event.ctrlKey || $event.metaKey })"
                            @endif
                        >
                            @if ($isEditable)
                                <button
                                    type="button"
                                    aria-label="{{ __('Select :date for :employee', ['date' => $day['date'], 'employee' => $employee->displayName()]) }}"
                                    class="block w-full {{ $cellMinWidth }} cursor-pointer text-center focus:outline-none focus-visible:ring-2 focus-visible:ring-accent focus-visible:ring-inset focus-visible:rounded-md"
                                >
                                    <x-people-attendance::day-tile
                                        :day-type="$dayType"
                                        :state="$isEmpty ? null : $cell['state']"
                                        :tooltip="$cell['title']"
                                        :empty="$isEmpty"
                                        :empty-label="$cell['label']"
                                    >
                                        <span class="text-[12px] font-semibold leading-tight text-current">{{ $cell['label'] }}</span>
                                        @if ($cell['on_non_working_day'] ?? false)
                                            <span class="text-[9px] font-medium uppercase leading-tight tracking-wide {{ $dayTypeInk }}">{{ $cell['day_type_label'] }}</span>
                                        @endif
                                    </x-people-attendance::day-tile>
                                </button>
                                {{-- Fill handle: visible on hover via CSS; activates drag-fill on mousedown --}}
                                <button
                                    type="button"
                                    aria-label="{{ __('Fill roster from :date for :employee', ['date' => $day['date'], 'employee' => $employee->displayName()]) }}"
                                    tabindex="-1"
                                    class="roster-fill-handle absolute bottom-0 right-0 z-10 h-3 w-3 -translate-x-px -translate-y-px cursor-crosshair rounded-full border-2 border-white bg-accent shadow-sm"
                                    data-fill-handle="{{ $employee->id }}:{{ $day['date'] }}"
                                    data-employee-id="{{ $employee->id }}"
                                    data-date="{{ $day['date'] }}"
                                    onmousedown="window.rosterGrid?.startFillDrag(event, this.dataset.employeeId, this.dataset.date)"
                                ></button>
                            @else
                                {{-- Read-only tile: locked period, actual mode, or non-manager --}}
                                <x-people-attendance::day-tile
                                    :day-type="$dayType"
                                    :state="$isEmpty ? null : $cell['state']"
                                    :tooltip="$isDateLocked ? __('Locked period') : $cell['title']"
                                    :empty="$isEmpty"
                                    :empty-label="$cell['label']"
                                >
                                    <span class="text-[12px] font-semibold leading-tight {{ $isDateLocked && ! $isEmpty ? 'text-muted' : 'text-current' }}">{{ $cell['label'] }}</span>
                                    @if ($cell['on_non_working_day'] ?? false)
                                        <span class="text-[9px] font-medium uppercase leading-tight tracking-wide {{ $dayTypeInk }}">{{ $cell['day_type_label'] }}</span>
                                    @endif
                                    @if ($actualOutcome && $actualOutcome !== 'matched' && $actualOutcome !== 'no_record')
                                        <span class="text-[9px] font-medium uppercase leading-tight tracking-wide {{ $actualOutcome === 'absent' ? 'text-danger' : 'text-warning' }}">{{ __($actualOutcome) }}</span>
                                    @endif
                                </x-people-attendance::day-tile>
                            @endif
                        </td>
                    @endforeach
                </tr>
            @empty
                <tr>
                    <td colspan="{{ count($rosterGridDays) + 1 }}" class="px-table-cell-x py-table-cell-y text-sm text-muted">
                        {{ __('No employees available for the roster grid.') }}
                    </td>
                </tr>
            @endforelse
        </tbody>
    </table>
</div>

@if ($showDayDrawer)
{{-- Day drawer: slides in when a date column header is clicked --}}
<div
    x-show="activeDate"
    x-cloak
    x-transition:enter="transition ease-out duration-150"
    x-transition:enter-start="opacity-0 translate-x-4"
    x-transition:enter-end="opacity-100 translate-x-0"
    x-transition:leave="transition ease-in duration-100"
    x-transition:leave-start="opacity-100 translate-x-0"
    x-transition:leave-end="opacity-0 translate-x-4"
    @keydown.escape.window="closeDrawer()"
    class="absolute right-0 top-10 z-20 w-72 rounded-2xl border border-border-default bg-surface-card shadow-lg"
>
    <template x-if="activeDay">
        <div>
            <div class="flex items-start justify-between gap-2 border-b border-border-default px-4 py-3">
                <div>
                    <div class="text-sm font-semibold text-ink" x-text="activeDay.label"></div>
                    <template x-if="activeDay.isHoliday">
                        <span class="mt-0.5 inline-flex items-center rounded-full bg-day-holiday px-2 py-0.5 text-[10px] font-medium text-day-holiday-ink">{{ __('Holiday') }}</span>
                    </template>
                    <template x-if="!activeDay.isHoliday && activeDay.isWeekend">
                        <span class="mt-0.5 inline-flex items-center rounded-full bg-day-rest px-2 py-0.5 text-[10px] font-medium text-day-rest-ink">{{ __('Weekend') }}</span>
                    </template>
                </div>
                <button type="button" @click="closeDrawer()" class="mt-0.5 rounded-md text-muted hover:text-ink focus:outline-none focus:ring-2 focus:ring-accent" aria-label="{{ __('Close') }}">
                    <x-icon name="heroicon-o-x-mark" class="h-4 w-4" />
                </button>
            </div>

            <div class="max-h-80 overflow-y-auto px-4 py-2">
                <template x-if="activeDay.entries.length === 0">
                    <p class="py-4 text-center text-sm text-muted">{{ __('No employees in the current view.') }}</p>
                </template>
                <template x-for="(entry, i) in activeDay.entries" :key="i">
                    <div class="flex items-center justify-between gap-2 border-b border-border-default/50 py-2 last:border-0">
                        <div class="min-w-0">
                            <div class="truncate text-sm font-medium text-ink" x-text="entry.name"></div>
                            <div class="text-[11px] text-muted" x-text="entry.title || entry.shift"></div>
                        </div>
                        <template x-if="!entry.empty">
                            <span class="shrink-0 rounded-full bg-accent/10 px-2 py-0.5 text-[10px] font-semibold text-accent" x-text="entry.shift"></span>
                        </template>
                        <template x-if="entry.empty">
                            <span class="shrink-0 text-[11px] text-muted">—</span>
                        </template>
                    </div>
                </template>
            </div>

            <div class="border-t border-border-default px-4 py-2.5">
                <span class="text-[11px] text-muted">
                    <span class="font-semibold text-ink" x-text="activeDay.assigned"></span>
                    {{ __('assigned') }}
                </span>
            </div>
        </div>
    </template>
</div>
@endif

@if ($canManage)
{{-- Swap modal --}}
<div x-show="swapModalOpen" x-cloak
     @keydown.escape.window="swapModalOpen = false"
     class="fixed inset-0 z-50 overflow-y-auto"
     style="display: none;">
    <div x-show="swapModalOpen"
         x-transition:enter="transition ease-out duration-200" x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100"
         x-transition:leave="transition ease-in duration-150" x-transition:leave-start="opacity-100" x-transition:leave-end="opacity-0"
         @click="swapModalOpen = false"
         class="fixed inset-0 bg-black/50"></div>
    <div class="flex min-h-full items-center justify-center p-4">
        <div x-show="swapModalOpen"
             x-transition:enter="transition ease-out duration-200" x-transition:enter-start="opacity-0 scale-95" x-transition:enter-end="opacity-100 scale-100"
             x-transition:leave="transition ease-in duration-150" x-transition:leave-start="opacity-100 scale-100" x-transition:leave-end="opacity-0 scale-95"
             @click.stop
             class="relative w-full max-w-md rounded-2xl border border-border-default bg-surface-card shadow-xl">
            <div class="space-y-4 p-6">
                <div>
                    <h2 class="text-lg font-semibold text-ink">{{ __('Swap Shift') }}</h2>
                    <p class="mt-1 text-sm text-muted">{{ __('Exchange the shift for a specific date between two employees.') }}</p>
                </div>
                <div class="rounded-lg border border-border-default bg-surface-subtle px-3 py-2 text-sm">
                    <span class="text-muted">{{ __('From:') }}</span>
                    <span class="ml-1 font-medium text-ink" x-text="swapFromName"></span>
                </div>
                <div>
                    <label for="roster-swap-target-employee" class="mb-1 block text-xs font-medium text-muted">{{ __('Swap with') }}</label>
                    <select id="roster-swap-target-employee" x-model="swapTargetEmpId"
                            class="w-full rounded-lg border border-border-default bg-surface-card px-2 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-accent">
                        <option value="">{{ __('— Select employee —') }}</option>
                        <template x-for="emp in swapTargetList" :key="emp.id">
                            <option :value="emp.id" x-text="emp.name"></option>
                        </template>
                    </select>
                </div>
                <div>
                    <label for="roster-swap-date" class="mb-1 block text-xs font-medium text-muted">{{ __('Date') }}</label>
                    <input id="roster-swap-date" type="date" x-model="swapDateStr"
                           class="w-full rounded-lg border border-border-default bg-surface-card px-2 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-accent" />
                </div>
                @error('swapDate')
                    <p class="text-xs text-red-600">{{ $message }}</p>
                @enderror
                <div class="flex justify-end gap-2 pt-1">
                    <x-ui.button type="button" variant="secondary" @click="swapModalOpen = false">{{ __('Cancel') }}</x-ui.button>
                    <x-ui.button type="button" variant="primary"
                                 x-bind:disabled="!swapTargetEmpId || !swapDateStr"
                                 @click="confirmSwap()">{{ __('Confirm swap') }}</x-ui.button>
                </div>
            </div>
        </div>
    </div>
</div>

{{-- Bulk assign modal --}}
<div x-show="bulkModalOpen" x-cloak
     @keydown.escape.window="bulkModalOpen = false"
     class="fixed inset-0 z-50 overflow-y-auto"
     style="display: none;">
    <div x-show="bulkModalOpen"
         x-transition:enter="transition ease-out duration-200" x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100"
         x-transition:leave="transition ease-in duration-150" x-transition:leave-start="opacity-100" x-transition:leave-end="opacity-0"
         @click="bulkModalOpen = false"
         class="fixed inset-0 bg-black/50"></div>
    <div class="flex min-h-full items-center justify-center p-4">
        <div x-show="bulkModalOpen"
             x-transition:enter="transition ease-out duration-200" x-transition:enter-start="opacity-0 scale-95" x-transition:enter-end="opacity-100 scale-100"
             x-transition:leave="transition ease-in duration-150" x-transition:leave-start="opacity-100 scale-100" x-transition:leave-end="opacity-0 scale-95"
             @click.stop
             class="relative w-full max-w-lg rounded-2xl border border-border-default bg-surface-card shadow-xl">
            <div class="space-y-4 p-6">
                <div>
                    <h2 class="text-lg font-semibold text-ink">{{ __('Bulk Assign') }}</h2>
                    <p class="mt-1 text-sm text-muted">
                        {{ __('Assigning to') }}
                        <span class="font-semibold text-ink" x-text="bulkEmpIds.length"></span>
                        {{ __('employee(s).') }}
                    </p>
                </div>
                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <label for="roster-bulk-shift-template" class="mb-1 block text-xs font-medium text-muted">{{ __('Shift') }} <span class="text-red-500">*</span></label>
                        <select id="roster-bulk-shift-template" wire:model="rosterShiftTemplateId"
                                class="w-full rounded-lg border border-border-default bg-surface-card px-2 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-accent">
                            <option value="">{{ __('— Select shift —') }}</option>
                            @foreach ($shiftTemplates as $shiftTpl)
                                <option value="{{ $shiftTpl->id }}">{{ $shiftTpl->code }} — {{ $shiftTpl->name }}</option>
                            @endforeach
                        </select>
                        @error('rosterShiftTemplateId')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                    </div>
                    <div>
                        <label for="roster-bulk-policy-group" class="mb-1 block text-xs font-medium text-muted">{{ __('Policy') }} <span class="text-red-500">*</span></label>
                        <select id="roster-bulk-policy-group" wire:model="rosterPolicyGroupId"
                                class="w-full rounded-lg border border-border-default bg-surface-card px-2 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-accent">
                            <option value="">{{ __('— Select policy —') }}</option>
                            @foreach ($policyGroups as $policyGrp)
                                <option value="{{ $policyGrp->id }}">{{ $policyGrp->code }} — {{ $policyGrp->name }}</option>
                            @endforeach
                        </select>
                        @error('rosterPolicyGroupId')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                    </div>
                </div>
                @if ($rosterPatterns->isNotEmpty())
                <div>
                    <label for="roster-bulk-pattern" class="mb-1 block text-xs font-medium text-muted">{{ __('Pattern (optional)') }}</label>
                    <select id="roster-bulk-pattern" wire:model="rosterPatternId"
                            class="w-full rounded-lg border border-border-default bg-surface-card px-2 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-accent">
                        <option value="">{{ __('— No pattern —') }}</option>
                        @foreach ($rosterPatterns as $pattern)
                            <option value="{{ $pattern->id }}">{{ $pattern->name }}</option>
                        @endforeach
                    </select>
                </div>
                @endif
                <div class="grid gap-4 sm:grid-cols-2">
                    <x-ui.input id="bulk-assign-from" type="date" wire:model="rosterEffectiveFrom"
                                label="{{ __('From') }}" :error="$errors->first('rosterEffectiveFrom')" />
                    <x-ui.input id="bulk-assign-to" type="date" wire:model="rosterEffectiveTo"
                                label="{{ __('To (blank = open-ended)') }}" :error="$errors->first('rosterEffectiveTo')" />
                </div>
                <div>
                    <label for="roster-bulk-note" class="mb-1 block text-xs font-medium text-muted">{{ __('Note (optional)') }}</label>
                    <textarea id="roster-bulk-note" x-model="bulkNote" rows="2"
                              class="w-full rounded-lg border border-border-default bg-surface-card px-2 py-1.5 text-sm placeholder-muted focus:outline-none focus:ring-2 focus:ring-accent"
                              placeholder="{{ __('Reason for this assignment') }}"></textarea>
                </div>
                @error('selectedRosterEmployeeIds')<p class="text-xs text-red-600">{{ $message }}</p>@enderror
                <div class="flex justify-end gap-2 pt-1">
                    <x-ui.button type="button" variant="secondary" @click="bulkModalOpen = false">{{ __('Cancel') }}</x-ui.button>
                    <x-ui.button type="button" variant="primary" @click="confirmBulkAssign()">{{ __('Assign') }}</x-ui.button>
                </div>
            </div>
        </div>
    </div>
</div>
@endif

</div>{{-- /Alpine rosterGrid wrapper --}}

<script>
document.addEventListener('alpine:init', () => {
Alpine.data('rosterGrid', (dayData) => ({
        // Day drawer
        dayData: dayData || {},
        activeDate: null,
        get activeDay() { return this.dayData[this.activeDate] ?? null; },
        openDrawer(date) { this.activeDate = date; },
        closeDrawer() { this.activeDate = null; },

        // Grid layout (built from DOM on init)
        employees: [],  // ordered employee ID strings
        dates: [],      // ordered date strings (Y-m-d)

        // Selection state
        selection: [],  // [{empId, date}]
        anchor: null,   // {empIdx, dateIdx}
        focus: null,    // {empIdx, dateIdx}

        // Row selection (employee-level, for bulk toolbar ops)
        selectedRows: [],   // employee ID strings
        rowAnchor: null,    // empIdx for shift-click range

        // Copy buffer
        copyBuffer: null,  // null | [{empOffset, dateOffset, shift, policy}]
        copySourceKeys: [], // 'empId:date' strings for marching-ants display

        // Fill drag state
        dragging: false,
        fillSourceKeys: [],   // 'empId:date' strings being dragged from
        fillPreviewKeys: [],  // 'empId:date' strings in the drag preview

        // Formula bar state
        barShift: 0,
        barPolicy: 0,
        barAddress: '',
        barMulti: false,
        barCount: 0,
        barNote: '',
        barJob: '',

        // Swap modal state
        swapModalOpen: false,
        swapFromName: '',
        swapTargetEmpId: '',
        swapDateStr: '',
        swapTargetList: [],

        // Bulk assign modal state
        bulkModalOpen: false,
        bulkEmpIds: [],
        bulkNote: '',

        init() {
            window.rosterGrid = this;
            this.$nextTick(() => this._buildLayout());
            this.$el.addEventListener('livewire:updated', () => {
                this.$nextTick(() => { this._buildLayout(); this._highlight(); });
            });
        },

        _buildLayout() {
            const table = this.$el.querySelector('table');
            if (!table) return;
            this.dates = Array.from(table.querySelectorAll('thead th[data-col-date]')).map(th => th.dataset.colDate);
            this.employees = Array.from(table.querySelectorAll('tbody tr[data-row-employee]')).map(tr => tr.dataset.rowEmployee);
        },

        _td(empId, date) {
            return this.$el.querySelector(`td[data-employee="${empId}"][data-date="${date}"]`);
        },

        _cellData(empId, date) {
            const td = this._td(empId, date);
            if (!td) return null;
            return { shift: parseInt(td.dataset.shift) || 0, policy: parseInt(td.dataset.policy) || 0, state: td.dataset.state || 'empty' };
        },

        _key(empId, date) { return `${empId}:${date}`; },

        _idx(empId, date) {
            const eIdx = this.employees.indexOf(String(empId));
            const dIdx = this.dates.indexOf(date);
            return (eIdx === -1 || dIdx === -1) ? null : { empIdx: eIdx, dateIdx: dIdx };
        },

        _range(from, to) {
            const out = [];
            const step = from <= to ? 1 : -1;
            for (let i = from; i !== to + step; i += step) out.push(i);
            return out;
        },

        _highlight() {
            this.$el.querySelectorAll('td.roster-selected').forEach(td => td.classList.remove('roster-selected'));
            this.$el.querySelectorAll('td.roster-fill-preview').forEach(td => td.classList.remove('roster-fill-preview'));
            this.$el.querySelectorAll('td.roster-copied').forEach(td => td.classList.remove('roster-copied'));
            this.$el.querySelectorAll('.roster-fill-handle').forEach(h => h.classList.remove('roster-handle-visible'));

            this.selection.forEach(({ empId, date }) => this._td(empId, date)?.classList.add('roster-selected'));
            this.fillPreviewKeys.forEach(k => {
                const [empId, date] = k.split(':');
                this._td(empId, date)?.classList.add('roster-fill-preview');
            });
            this.copySourceKeys.forEach(k => {
                const [empId, date] = k.split(':');
                this._td(empId, date)?.classList.add('roster-copied');
            });

            // Keep fill handle visible on the focused cell (CSS hover shows it on any cell)
            if (this.focus && this.selection.length > 0) {
                const empId = this.employees[this.focus.empIdx];
                const date = this.dates[this.focus.dateIdx];
                const handle = this.$el.querySelector(`[data-fill-handle="${empId}:${date}"]`);
                handle?.classList.add('roster-handle-visible');
            }

            this._updateBar();
        },

        _updateBar() {
            if (this.selection.length === 0) {
                this.barCount = 0; this.barAddress = ''; this.barShift = 0; this.barPolicy = 0; this.barMulti = false;
                return;
            }
            this.barCount = this.selection.length;
            this.barMulti = this.selection.length > 1;
            if (!this.barMulti) {
                const s = this.selection[0];
                const data = this._cellData(s.empId, s.date);
                this.barShift = data?.shift || 0;
                this.barPolicy = data?.policy || 0;
                const tr = this.$el.querySelector(`tr[data-row-employee="${s.empId}"]`);
                const name = tr?.querySelector('td:first-child .truncate')?.textContent?.trim() || `#${s.empId}`;
                this.barAddress = `${name} ${s.date}`;
            } else {
                const shifts = new Set(this.selection.map(s => this._cellData(s.empId, s.date)?.shift || 0));
                const policies = new Set(this.selection.map(s => this._cellData(s.empId, s.date)?.policy || 0));
                this.barShift = shifts.size === 1 ? [...shifts][0] : 0;
                this.barPolicy = policies.size === 1 ? [...policies][0] : 0;
                this.barAddress = '';
            }
        },

        saveBar() {
            if (!this.barShift || !this.barPolicy || this.barCount === 0) return;
            if (this.barCount === 1) {
                const s = this.selection[0];
                this.$wire.saveCellOverride(parseInt(s.empId), s.date, this.barShift, this.barPolicy, this.barNote, this.barJob);
            } else {
                const overrides = this.selection.map(s => ({
                    employee_id: parseInt(s.empId), date: s.date,
                    shift_template_id: this.barShift, policy_group_id: this.barPolicy,
                }));
                this.$wire.saveCellOverrides(overrides, this.barNote, this.barJob);
            }
        },

        // Cell selection (clears any row selection first)
        handleCellSelect({ empId, date, extendShift, toggle }) {
            this.selectedRows = []; this.rowAnchor = null;
            const idx = this._idx(empId, date);
            if (!idx) return;

            if (extendShift && this.anchor) {
                // Rectangle from anchor to target
                const minE = Math.min(this.anchor.empIdx, idx.empIdx);
                const maxE = Math.max(this.anchor.empIdx, idx.empIdx);
                const minD = Math.min(this.anchor.dateIdx, idx.dateIdx);
                const maxD = Math.max(this.anchor.dateIdx, idx.dateIdx);
                this.selection = [];
                for (let e = minE; e <= maxE; e++) {
                    for (let d = minD; d <= maxD; d++) {
                        this.selection.push({ empId: this.employees[e], date: this.dates[d] });
                    }
                }
                this.focus = idx;
            } else if (toggle) {
                const key = this._key(empId, date);
                const exists = this.selection.findIndex(s => this._key(s.empId, s.date) === key);
                if (exists >= 0) {
                    this.selection.splice(exists, 1);
                } else {
                    this.selection.push({ empId: String(empId), date });
                    this.anchor = idx;
                    this.focus = idx;
                }
            } else {
                this.selection = [{ empId: String(empId), date }];
                this.anchor = idx;
                this.focus = idx;
            }

            this._highlight();
        },

        // Toolbar actions
        selectAllRows() {
            this.selection = []; this.anchor = null; this.focus = null;
            this.copyBuffer = null; this.copySourceKeys = [];
            this.selectedRows = [...this.employees];
            this._highlight();
        },

        toolbarCopyPrevious() {
            const ids = this.selectedRows.length > 0 ? this.selectedRows : this.employees;
            this.$wire.set('selectedRosterEmployeeIds', ids);
            this.$wire.copyPreviousPeriod();
        },

        // Row selection (clears any cell selection first)
        handleRowSelect(event, empId) {
            const empIdx = this.employees.indexOf(String(empId));
            if (empIdx === -1) return;
            this.selection = []; this.anchor = null; this.focus = null;
            this.copyBuffer = null; this.copySourceKeys = [];

            if (event.shiftKey && this.rowAnchor !== null) {
                const min = Math.min(this.rowAnchor, empIdx);
                const max = Math.max(this.rowAnchor, empIdx);
                const rangeIds = this.employees.slice(min, max + 1);
                rangeIds.forEach(id => { if (!this.selectedRows.includes(id)) this.selectedRows.push(id); });
            } else if (event.ctrlKey || event.metaKey) {
                const pos = this.selectedRows.indexOf(String(empId));
                if (pos >= 0) { this.selectedRows.splice(pos, 1); }
                else { this.selectedRows.push(String(empId)); this.rowAnchor = empIdx; }
            } else {
                // Toggle off if this is the only selected row, otherwise select just this one
                if (this.selectedRows.length === 1 && this.selectedRows[0] === String(empId)) {
                    this.selectedRows = [];
                    this.rowAnchor = null;
                } else {
                    this.selectedRows = [String(empId)];
                    this.rowAnchor = empIdx;
                }
            }
            this._highlight();
        },

        clearSelection() {
            this.selection = [];
            this.anchor = null;
            this.focus = null;
            this.copyBuffer = null;
            this.copySourceKeys = [];
            this.selectedRows = [];
            this.rowAnchor = null;
            this._highlight();
        },

        // Copy
        copySelection() {
            if (this.selection.length === 0) return;
            const minE = Math.min(...this.selection.map(s => this._idx(s.empId, s.date)?.empIdx ?? 0));
            const minD = Math.min(...this.selection.map(s => this._idx(s.empId, s.date)?.dateIdx ?? 0));
            this.copyBuffer = this.selection.map(s => {
                const idx = this._idx(s.empId, s.date);
                const data = this._cellData(s.empId, s.date);
                return { empOffset: (idx?.empIdx ?? 0) - minE, dateOffset: (idx?.dateIdx ?? 0) - minD, shift: data?.shift || 0, policy: data?.policy || 0 };
            });
            this.copySourceKeys = this.selection.map(s => this._key(s.empId, s.date));
            this._highlight();
        },

        // Paste
        pasteAtFocus() {
            if (!this.copyBuffer || !this.focus) return;
            const overrides = this.copyBuffer.map(c => {
                const eIdx = this.focus.empIdx + c.empOffset;
                const dIdx = this.focus.dateIdx + c.dateOffset;
                if (eIdx < 0 || eIdx >= this.employees.length || dIdx < 0 || dIdx >= this.dates.length) return null;
                return { employee_id: parseInt(this.employees[eIdx]), date: this.dates[dIdx], shift_template_id: c.shift, policy_group_id: c.policy };
            }).filter(Boolean);
            if (overrides.length > 0) this.$wire.saveCellOverrides(overrides);
            this.copySourceKeys = [];
            this.copyBuffer = null;
            this._highlight();
        },

        // Delete
        deleteSelection() {
            if (this.selection.length === 0) return;
            const overrides = this.selection.map(s => ({ employee_id: parseInt(s.empId), date: s.date, shift_template_id: 0, policy_group_id: 0 }));
            this.$wire.saveCellOverrides(overrides);
            this.clearSelection();
        },

        // Fill drag
        startFillDrag(event, empId, date) {
            event.preventDefault();
            event.stopPropagation();
            this.selectedRows = []; this.rowAnchor = null;
            if (this.selection.length === 0) {
                this.selection = [{ empId: String(empId), date }];
                this.anchor = this._idx(empId, date);
                this.focus = this.anchor;
            }
            this.dragging = true;
            this.fillSourceKeys = this.selection.map(s => this._key(s.empId, s.date));
            this.fillPreviewKeys = [...this.fillSourceKeys];
            this._highlight();

            const onMove = (e) => {
                const el = document.elementFromPoint(e.clientX, e.clientY);
                const td = el?.closest?.('td[data-employee]');
                if (td) this._updateFillPreview(td.dataset.employee, td.dataset.date);
            };
            const onUp = (e) => {
                document.removeEventListener('mousemove', onMove);
                document.removeEventListener('mouseup', onUp);
                const el = document.elementFromPoint(e.clientX, e.clientY);
                const td = el?.closest?.('td[data-employee]');
                if (td) this._commitFill(td.dataset.employee, td.dataset.date);
                this.dragging = false;
                this.fillPreviewKeys = [];
                this._highlight();
            };
            document.addEventListener('mousemove', onMove);
            document.addEventListener('mouseup', onUp);
        },

        _updateFillPreview(targetEmpId, targetDate) {
            const targetIdx = this._idx(targetEmpId, targetDate);
            if (!targetIdx) return;

            const srcEIdxs = [...new Set(this.selection.map(s => this._idx(s.empId, s.date)?.empIdx).filter(x => x != null))];
            const srcDIdxs = [...new Set(this.selection.map(s => this._idx(s.empId, s.date)?.dateIdx).filter(x => x != null))].sort((a, b) => a - b);
            const minSE = Math.min(...srcEIdxs), maxSE = Math.max(...srcEIdxs);
            const minSD = Math.min(...srcDIdxs), maxSD = Math.max(...srcDIdxs);

            const keys = new Set(this.fillSourceKeys);
            if (targetIdx.dateIdx > maxSD) {
                srcEIdxs.forEach(e => { this._range(maxSD + 1, targetIdx.dateIdx).forEach(d => { if (d < this.dates.length) keys.add(this._key(this.employees[e], this.dates[d])); }); });
            } else if (targetIdx.dateIdx < minSD) {
                srcEIdxs.forEach(e => { this._range(targetIdx.dateIdx, minSD - 1).forEach(d => { if (d >= 0) keys.add(this._key(this.employees[e], this.dates[d])); }); });
            } else if (targetIdx.empIdx > maxSE) {
                srcDIdxs.forEach(d => { this._range(maxSE + 1, targetIdx.empIdx).forEach(e => { if (e < this.employees.length) keys.add(this._key(this.employees[e], this.dates[d])); }); });
            } else if (targetIdx.empIdx < minSE) {
                srcDIdxs.forEach(d => { this._range(targetIdx.empIdx, minSE - 1).forEach(e => { if (e >= 0) keys.add(this._key(this.employees[e], this.dates[d])); }); });
            }
            this.fillPreviewKeys = Array.from(keys);
            this._highlight();
        },

        _detectCyclePeriod(seq) {
            const n = seq.length;
            for (let p = 1; p <= Math.min(14, Math.floor(n / 2)); p++) {
                if (seq.every((_, i) => i < p || seq[i].shift === seq[i % p].shift)) return p;
            }
            return n;
        },

        _commitFill(targetEmpId, targetDate) {
            const targetIdx = this._idx(targetEmpId, targetDate);
            if (!targetIdx || this.selection.length === 0) return;

            const srcEIdxs = [...new Set(this.selection.map(s => this._idx(s.empId, s.date)?.empIdx).filter(x => x != null))].sort((a, b) => a - b);
            const srcDIdxs = [...new Set(this.selection.map(s => this._idx(s.empId, s.date)?.dateIdx).filter(x => x != null))].sort((a, b) => a - b);
            const minSE = Math.min(...srcEIdxs), maxSE = Math.max(...srcEIdxs);
            const minSD = Math.min(...srcDIdxs), maxSD = Math.max(...srcDIdxs);

            // Source sequence for cycle detection (first row's shifts ordered by date)
            const srcSeq = srcDIdxs.map(d => this._cellData(this.employees[srcEIdxs[0]], this.dates[d]) || { shift: 0, policy: 0 });
            const period = this._detectCyclePeriod(srcSeq);

            const overrides = [];

            if (targetIdx.dateIdx > maxSD) {
                const fillDs = this._range(maxSD + 1, targetIdx.dateIdx);
                srcEIdxs.forEach(e => {
                    fillDs.forEach((d, i) => {
                        if (d >= this.dates.length) return;
                        const src = this._cellData(this.employees[srcEIdxs[e - minSE] ?? srcEIdxs[0]], this.dates[srcDIdxs[i % period]]);
                        if (!src || !src.shift || !src.policy) return;
                        overrides.push({ employee_id: parseInt(this.employees[e]), date: this.dates[d], shift_template_id: src.shift, policy_group_id: src.policy });
                    });
                });
            } else if (targetIdx.dateIdx < minSD) {
                const fillDs = this._range(targetIdx.dateIdx, minSD - 1).reverse();
                srcEIdxs.forEach(e => {
                    fillDs.forEach((d, i) => {
                        if (d < 0) return;
                        const src = this._cellData(this.employees[srcEIdxs[e - minSE] ?? srcEIdxs[0]], this.dates[srcDIdxs[i % period]]);
                        if (!src || !src.shift || !src.policy) return;
                        overrides.push({ employee_id: parseInt(this.employees[e]), date: this.dates[d], shift_template_id: src.shift, policy_group_id: src.policy });
                    });
                });
            } else if (targetIdx.empIdx > maxSE) {
                const fillEs = this._range(maxSE + 1, targetIdx.empIdx);
                fillEs.forEach((e, rowI) => {
                    srcDIdxs.forEach((d, colI) => {
                        const srcE = srcEIdxs[rowI % srcEIdxs.length];
                        const src = this._cellData(this.employees[srcE], this.dates[d]);
                        if (!src || !src.shift || !src.policy) return;
                        overrides.push({ employee_id: parseInt(this.employees[e]), date: this.dates[d], shift_template_id: src.shift, policy_group_id: src.policy });
                    });
                });
            } else if (targetIdx.empIdx < minSE) {
                const fillEs = this._range(targetIdx.empIdx, minSE - 1).reverse();
                fillEs.forEach((e, rowI) => {
                    srcDIdxs.forEach((d, colI) => {
                        const srcE = srcEIdxs[rowI % srcEIdxs.length];
                        const src = this._cellData(this.employees[srcE], this.dates[d]);
                        if (!src || !src.shift || !src.policy) return;
                        overrides.push({ employee_id: parseInt(this.employees[e]), date: this.dates[d], shift_template_id: src.shift, policy_group_id: src.policy });
                    });
                });
            }

            if (overrides.length > 0) this.$wire.saveCellOverrides(overrides);
        },

        // Cell history
        openHistory() {
            if (this.selection.length !== 1) return;
            const s = this.selection[0];
            this.$wire.loadCellHistory(parseInt(s.empId), s.date);
        },

        // Swap modal
        _empName(empId) {
            const tr = this.$el.querySelector(`tr[data-row-employee="${empId}"]`);
            return tr?.querySelector('td:first-child .truncate')?.textContent?.trim() || `#${empId}`;
        },

        openSwapModal(empId) {
            const id = String(empId);
            this.swapFromName = this._empName(id);
            this.swapTargetList = this.employees
                .filter(eid => eid !== id)
                .map(eid => ({ id: eid, name: this._empName(eid) }));
            this.swapTargetEmpId = '';
            this.swapDateStr = this.dates[0] || '';
            this.$wire.set('swapFromEmployeeId', id);
            this.swapModalOpen = true;
        },

        confirmSwap() {
            this.$wire.set('swapToEmployeeId', this.swapTargetEmpId);
            this.$wire.set('swapDate', this.swapDateStr);
            this.$wire.swapRosterCells();
        },

        // Bulk assign modal
        openBulkModal(empIds) {
            this.bulkEmpIds = empIds.map(String);
            this.bulkNote = '';
            this.$wire.set('selectedRosterEmployeeIds', this.bulkEmpIds);
            this.bulkModalOpen = true;
        },

        confirmBulkAssign() {
            this.$wire.set('rosterBulkNote', this.bulkNote);
            this.$wire.saveRosterAssignment();
        },

        // Keyboard
        handleKeydown(event) {
            if (this.selection.length === 0 && !this.copyBuffer) return;
            if (['INPUT', 'SELECT', 'TEXTAREA'].includes(event.target.tagName)) return;

            if ((event.ctrlKey || event.metaKey) && event.key === 'c') {
                this.copySelection();
                event.preventDefault();
            } else if ((event.ctrlKey || event.metaKey) && event.key === 'v') {
                this.pasteAtFocus();
                event.preventDefault();
            } else if (event.key === 'Delete' || event.key === 'Backspace') {
                this.deleteSelection();
                event.preventDefault();
            } else if (event.key === 'Escape') {
                this.clearSelection();
            }
        },
}));
});
</script>
