<?php

use App\Modules\People\Attendance\Livewire\PolicyGroups;

/** @var PolicyGroups $this */
?>

<div>
    <x-slot name="title">{{ __('Policy Groups') }}</x-slot>

    @php($pickerVisible = $mode === 'form' && $selectedPolicyTemplateKey !== 'saved-policy')

    <div class="space-y-section-gap">
        <x-ui.page-header
            :title="__('Policy Groups')"
            :subtitle="$pickerVisible ? __('Rule bundles — work hours, lateness, overtime, payroll mapping — that rosters point employees at.') : null">
            @if ($mode === 'list')
                <x-slot name="actions">
                    <x-ui.button type="button" variant="primary" wire:click="startNewPolicy">
                        <x-icon name="heroicon-o-plus-circle" class="h-4 w-4" />
                        {{ __('New policy') }}
                    </x-ui.button>
                </x-slot>
            @endif
            @if ($pickerVisible)
                <x-slot name="help">
                    {{ __('Each saved policy is versioned, so attendance days resolved against an older version stay reproducible. Use Simulate to preview changes before publishing.') }}
                </x-slot>
            @endif
        </x-ui.page-header>

        @if (session('success'))
            <x-ui.alert variant="success">{{ session('success') }}</x-ui.alert>
        @endif

        @if (session('error'))
            <x-ui.alert variant="danger">{{ session('error') }}</x-ui.alert>
        @endif

        @if (! $schemaReady)
            <x-ui.alert variant="warning">
                {{ __('Attendance database tables are not installed yet. Run the Attendance migration before using timecards, clock events, overtime, and payroll handoff screens.') }}
            </x-ui.alert>
        @endif

        @if ($mode === 'list')
            <x-ui.card>
                <div class="overflow-x-auto -mx-card-inner px-card-inner">
                    <table class="min-w-full divide-y divide-border-default text-sm">
                        <thead class="bg-surface-subtle/80">
                            <tr>
                                <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold uppercase tracking-wider text-muted">{{ __('No.') }}</th>
                                <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold uppercase tracking-wider text-muted">{{ __('Policy group') }}</th>
                                <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold uppercase tracking-wider text-muted">{{ __('Status') }}</th>
                                <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold uppercase tracking-wider text-muted">{{ __('Version') }}</th>
                                <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold uppercase tracking-wider text-muted">{{ __('Effective') }}</th>
                                <th class="px-table-cell-x py-table-header-y text-right text-[11px] font-semibold uppercase tracking-wider text-muted">{{ __('Actions') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-border-default bg-surface-card">
                            @forelse ($policyGroups as $group)
                                <tr wire:key="policy-group-row-{{ $group->id }}">
                                    <td class="px-table-cell-x py-table-cell-y text-xs text-muted tabular-nums">{{ $loop->iteration }}</td>
                                    <td class="px-table-cell-x py-table-cell-y">
                                        <button type="button" class="text-left font-medium text-accent hover:underline" wire:click="editPolicyGroup({{ $group->id }})">{{ $group->name }}</button>
                                        <div class="font-mono text-xs text-muted">{{ $group->code }}</div>
                                    </td>
                                    <td class="px-table-cell-x py-table-cell-y">
                                        <x-ui.button type="button" size="sm" :variant="$group->status === 'active' ? 'primary' : 'secondary'" wire:click="togglePolicyStatus({{ $group->id }})">{{ __(ucfirst($group->status)) }}</x-ui.button>
                                    </td>
                                    <td class="px-table-cell-x py-table-cell-y tabular-nums">{{ $group->version }}</td>
                                    <td class="px-table-cell-x py-table-cell-y text-xs text-muted">{{ $group->effective_from?->format('Y-m-d') ?? '-' }}</td>
                                    <td class="px-table-cell-x py-table-cell-y">
                                        <div class="flex justify-end gap-2">
                                            <x-ui.button type="button" size="sm" variant="secondary" wire:click="simulatePolicyGroup({{ $group->id }})">{{ __('Simulate') }}</x-ui.button>
                                            <x-ui.button type="button" size="sm" variant="secondary" wire:click="duplicatePolicyGroup({{ $group->id }})">{{ __('Duplicate') }}</x-ui.button>
                                            <x-ui.button type="button" size="sm" variant="secondary" wire:click="exportPolicyGroupTemplate({{ $group->id }})">{{ __('Download') }}</x-ui.button>
                                        </div>
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="6" class="px-table-cell-x py-10 text-center text-sm text-muted">{{ __('No policy groups yet. Click "New policy" to start from a template.') }}</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </x-ui.card>

            @if ($policyTemplateExportJson !== '')
                @include('people-attendance::livewire.people.attendance.partials.template-json-export', [
                    'id' => 'attendance-policy-group-template-export',
                    'field' => 'policyTemplateExportJson',
                    'description' => __('Copy this JSON into a shared template repository or country pack. Upload it from the builder when needed.'),
                ])
            @endif
        @else
            @include('people-attendance::livewire.people.attendance.partials.policy-form')
        @endif
    </div>
</div>
