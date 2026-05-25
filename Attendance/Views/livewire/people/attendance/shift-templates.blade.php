<?php

use App\Modules\People\Attendance\Livewire\ShiftTemplates;

/** @var ShiftTemplates $this */
?>

<div>
    <x-slot name="title">{{ __('Shifts') }}</x-slot>

    <div class="space-y-section-gap">
        <x-ui.page-header
            :title="__('Shifts')"
            :subtitle="__('Work shifts — schedule, break, clock-in/out tolerances — that rosters assign to employees.')">
            @if ($mode === 'list')
                <x-slot name="actions">
                    <x-ui.button type="button" variant="primary" wire:click="startNewShift">
                        <x-icon name="heroicon-o-plus-circle" class="h-4 w-4" />
                        {{ __('New shift') }}
                    </x-ui.button>
                </x-slot>
            @endif
            <x-slot name="help">
                {{ __('Each shift defines a daily start/end, expected work minutes (excluding the break), break window, and how early or late a clock-in or clock-out is accepted. Rosters assign one shift per employee per date range; clock events are validated against that shift.') }}
            </x-slot>
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
            @include('people-attendance::livewire.people.attendance.partials.shift-templates-table')
        @else
            @include('people-attendance::livewire.people.attendance.partials.shift-template-form')
        @endif
    </div>
</div>
