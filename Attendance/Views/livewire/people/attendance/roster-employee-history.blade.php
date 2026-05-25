<?php

use App\Modules\People\Attendance\Livewire\RosterEmployeeHistory;

/** @var RosterEmployeeHistory $this */
?>

<div>
    <x-slot name="title">{{ __('Roster Change History') }}</x-slot>

    <div class="space-y-section-gap">
        <x-ui.page-header
            :title="$employee ? __(':name — Roster History', ['name' => $employee->displayName()]) : __('Roster Change History')"
            :subtitle="__('All roster cell changes for this employee, in reverse chronological order.')"
        />

        @if (! $employee)
            <x-ui.alert variant="warning">{{ __('No employee selected.') }}</x-ui.alert>
        @else
            {{-- Date filter --}}
            <div class="flex flex-wrap items-end gap-3 rounded-xl border border-border-default bg-surface-card p-4">
                <x-ui.input id="roster-history-from-date" type="date" wire:model.live="fromDate" label="{{ __('From') }}" />
                <x-ui.input id="roster-history-to-date" type="date" wire:model.live="toDate" label="{{ __('To') }}" />
            </div>

            {{-- History table --}}
            <div class="overflow-x-auto rounded-2xl border border-border-default">
                <table class="min-w-full divide-y divide-border-default text-sm">
                    <thead class="bg-surface-subtle">
                        <tr>
                            <th class="px-table-cell-x py-table-cell-y text-left text-xs font-semibold uppercase tracking-wide text-muted">{{ __('Date') }}</th>
                            <th class="px-table-cell-x py-table-cell-y text-left text-xs font-semibold uppercase tracking-wide text-muted">{{ __('Changed at') }}</th>
                            <th class="px-table-cell-x py-table-cell-y text-left text-xs font-semibold uppercase tracking-wide text-muted">{{ __('By') }}</th>
                            <th class="px-table-cell-x py-table-cell-y text-left text-xs font-semibold uppercase tracking-wide text-muted">{{ __('Action') }}</th>
                            <th class="px-table-cell-x py-table-cell-y text-left text-xs font-semibold uppercase tracking-wide text-muted">{{ __('Previous') }}</th>
                            <th class="px-table-cell-x py-table-cell-y text-left text-xs font-semibold uppercase tracking-wide text-muted">{{ __('New') }}</th>
                            <th class="px-table-cell-x py-table-cell-y text-left text-xs font-semibold uppercase tracking-wide text-muted">{{ __('Note / Job') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-border-default bg-surface-card">
                        @forelse ($rows as $log)
                            <tr class="hover:bg-surface-subtle/50">
                                <td class="px-table-cell-x py-table-cell-y font-medium text-ink">
                                    {{ \Carbon\CarbonImmutable::parse($log->subject_identifier)->format('d M Y') }}
                                </td>
                                <td class="px-table-cell-x py-table-cell-y text-muted">
                                    {{ $log->occurred_at?->format('d M Y, H:i') ?? '—' }}
                                </td>
                                <td class="px-table-cell-x py-table-cell-y text-muted">
                                    {{ $log->actor_type === \App\Base\Authz\Enums\PrincipalType::USER->value && $log->actor_id !== null ? ($userNames[$log->actor_id] ?? __('Unknown')) : __('System') }}
                                </td>
                                <td class="px-table-cell-x py-table-cell-y">
                                    @php($actionVariant = match($log->event) { 'created' => 'success', 'deleted' => 'danger', default => 'default' })
                                    <x-ui.badge :variant="$actionVariant">{{ __($log->event) }}</x-ui.badge>
                                </td>
                                <td class="px-table-cell-x py-table-cell-y text-muted">
                                    @if (($log->old_values['shift_code'] ?? null) || ($log->old_values['policy_code'] ?? null))
                                        <span class="font-medium text-ink">{{ $log->old_values['shift_code'] ?? '—' }}</span>
                                        <span class="text-xs"> / {{ $log->old_values['policy_code'] ?? '—' }}</span>
                                    @else
                                        <span class="text-muted">—</span>
                                    @endif
                                </td>
                                <td class="px-table-cell-x py-table-cell-y">
                                    @if (($log->new_values['shift_code'] ?? null) || ($log->new_values['policy_code'] ?? null))
                                        <span class="font-medium text-ink">{{ $log->new_values['shift_code'] ?? '—' }}</span>
                                        <span class="text-xs text-muted"> / {{ $log->new_values['policy_code'] ?? '—' }}</span>
                                    @else
                                        <span class="text-muted">—</span>
                                    @endif
                                </td>
                                <td class="px-table-cell-x py-table-cell-y text-muted">
                                    —
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="7" class="px-table-cell-x py-table-cell-y text-center text-muted">
                                    {{ __('No change history for this date range.') }}
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            @if ($rows->hasPages())
                <div class="mt-4">
                    {{ $rows->links() }}
                </div>
            @endif
        @endif
    </div>
</div>
