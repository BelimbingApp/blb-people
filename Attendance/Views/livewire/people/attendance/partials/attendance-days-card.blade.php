@php($actionsColumn = $surface === 'operations' && $canManage)

<x-ui.card>
    <div class="mb-4 flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
        <div class="flex flex-1 flex-col gap-3 sm:flex-row">
            <x-ui.search-input
                wire:model.live.debounce.300ms="search"
                placeholder="{{ __('Search employee...') }}"
            />
            <x-ui.select id="attendance-status" wire:model.live="status">
                <option value="">{{ __('All statuses') }}</option>
                @foreach ($statusOptions as $value => $label)
                    <option value="{{ $value }}">{{ $label }}</option>
                @endforeach
            </x-ui.select>
        </div>

        @if ($surface === 'my' && $canClock)
            <div class="flex gap-2">
                <x-ui.button type="button" variant="secondary" wire:click="openOvertimeModal" :disabled="$currentEmployeeId === null || ! $schemaReady">
                    <x-icon name="heroicon-o-plus-circle" class="h-4 w-4" />
                    {{ __('Request OT') }}
                </x-ui.button>
                <x-ui.button type="button" variant="primary" wire:click="clock('in')" :disabled="$currentEmployeeId === null || ! $schemaReady">
                    <x-icon name="heroicon-o-arrow-right-on-rectangle" class="h-4 w-4" />
                    {{ __('Clock In') }}
                </x-ui.button>
                <x-ui.button type="button" variant="secondary" wire:click="clock('out')" :disabled="$currentEmployeeId === null || ! $schemaReady">
                    <x-icon name="heroicon-o-arrow-left-on-rectangle" class="h-4 w-4" />
                    {{ __('Clock Out') }}
                </x-ui.button>
            </div>
        @endif
    </div>

    @if ($surface === 'my' && $currentEmployeeId === null)
        <x-ui.alert variant="warning" class="mb-4">{{ __('Your user account is not linked to an employee record, so web clocking is disabled.') }}</x-ui.alert>
    @endif

    <x-ui.table container="flush" :caption="__('Attendance days')" :row-hover="false">
        <x-slot name="head">
        <tr>
            <x-ui.th>{{ __('Date') }}</x-ui.th>
            <x-ui.th>{{ __('Employee') }}</x-ui.th>
            <x-ui.th>{{ __('Shift') }}</x-ui.th>
            <x-ui.th align="right">{{ __('Worked') }}</x-ui.th>
            <x-ui.th align="right">{{ __('Late') }}</x-ui.th>
            <x-ui.th align="right">{{ __('OT Candidate') }}</x-ui.th>
            <x-ui.th>{{ __('Status') }}</x-ui.th>
            <x-ui.th>{{ __('Exceptions') }}</x-ui.th>
            @if ($actionsColumn)
                <x-ui.th align="right">{{ __('Actions') }}</x-ui.th>
            @endif
        </tr>
        </x-slot>

        @forelse ($attendanceDays as $day)
            <tr wire:key="attendance-day-{{ $day->id }}">
                <td class="px-table-cell-x py-table-cell-y font-mono text-xs text-ink">{{ $day->attendance_date?->format('Y-m-d') }}</td>
                <td class="px-table-cell-x py-table-cell-y">
                    <div class="font-medium text-ink">{{ $day->employee?->full_name ?? __('Employee #:id', ['id' => $day->employee_id]) }}</div>
                    <div class="font-mono text-xs text-muted">{{ $day->employee?->employee_number }}</div>
                </td>
                <td class="px-table-cell-x py-table-cell-y">
                    <div class="text-ink">{{ $day->shiftTemplate?->name ?? __('Unassigned') }}</div>
                    <div class="text-xs text-muted">{{ $day->policyGroup?->code }}</div>
                </td>
                <td class="px-table-cell-x py-table-cell-y text-right tabular-nums">{{ number_format($day->worked_minutes / 60, 2) }}</td>
                <td class="px-table-cell-x py-table-cell-y text-right tabular-nums">{{ $day->late_minutes }}</td>
                <td class="px-table-cell-x py-table-cell-y text-right tabular-nums">{{ number_format($day->overtime_candidate_minutes / 60, 2) }}</td>
                <td class="px-table-cell-x py-table-cell-y"><x-ui.badge :variant="$this->statusVariant($day->status)">{{ $this->statusLabel($day->status) }}</x-ui.badge></td>
                <td class="px-table-cell-x py-table-cell-y text-xs text-muted">
                    {{ collect($day->exception_tags ?? [])->map(fn ($tag) => str_replace('_', ' ', $tag))->implode(', ') ?: '-' }}
                </td>
                @if ($actionsColumn)
                    <td class="px-table-cell-x py-table-cell-y">
                        <div class="flex justify-end gap-2">
                            @if (in_array($day->status, ['ready_for_review', 'exception_pending'], true))
                                <x-ui.button size="sm" type="button" variant="primary" wire:click="finalizeDay({{ $day->id }})">{{ __('Finalize') }}</x-ui.button>
                            @endif
                            @if ($day->locked_at === null && $day->status !== 'locked')
                                <x-ui.button size="sm" type="button" variant="secondary" wire:click="lockDay({{ $day->id }})">{{ __('Lock') }}</x-ui.button>
                            @endif
                        </div>
                    </td>
                @endif
            </tr>
        @empty
            <tr>
                <td colspan="{{ $actionsColumn ? 9 : 8 }}" class="px-table-cell-x py-10 text-center text-sm text-muted">{{ __('No attendance days found.') }}</td>
            </tr>
        @endforelse
    </x-ui.table>
</x-ui.card>
