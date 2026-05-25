<?php

use App\Modules\People\Attendance\Livewire\PolicyGroupValidator;

/** @var PolicyGroupValidator $this */
?>

<div>
    <x-slot name="title">{{ __('Policy Validator') }}</x-slot>

    <div class="space-y-section-gap">
        <x-ui.page-header :title="__('Policy Validator')" :subtitle="__('Validate policy groups and simulate attendance outcomes before rules affect rosters or payroll.')">
            <x-slot name="help">
                {{ __('Validation checks rule shape — rounding methods, grace ranges, band ordering, missing pay items. Simulation runs one (date, clock-in, clock-out) tuple against a policy plus shift template without persisting anything.') }}
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

        @include('people-attendance::livewire.people.attendance.partials.policy-group-validator-body')
    </div>
</div>
