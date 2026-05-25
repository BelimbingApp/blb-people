<?php

use App\Modules\People\Attendance\Livewire\AllowanceRules;

/** @var AllowanceRules $this */
?>

<div>
    <x-slot name="title">{{ __('Allowance Rules') }}</x-slot>

    <div class="space-y-section-gap">
        <x-ui.page-header :title="__('Allowance Rules')" :subtitle="__('Maintain attendance-driven allowance rules and their payroll pay item mappings.')">
            @if ($mode === 'list')
                <x-slot name="actions">
                    <x-ui.button type="button" variant="primary" wire:click="startNewAllowanceRule">
                        <x-icon name="heroicon-o-plus-circle" class="h-4 w-4" />
                        {{ __('New allowance') }}
                    </x-ui.button>
                </x-slot>
            @endif
            <x-slot name="help">
                {{ __('Each rule pays one payroll pay item when its predicate matches — minutes worked, clock-out time, or both. Rules can target a specific policy group or apply when none is linked.') }}
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
            @include('people-attendance::livewire.people.attendance.partials.allowance-rules-table')
        @else
            @include('people-attendance::livewire.people.attendance.partials.allowance-rule-form')
        @endif
    </div>
</div>
