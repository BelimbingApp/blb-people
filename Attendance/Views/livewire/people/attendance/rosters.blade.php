<?php

use App\Modules\People\Attendance\Livewire\Rosters;

/** @var Rosters $this */
?>

<div>
    <x-slot name="title">{{ __('Roster') }}</x-slot>

    <div class="space-y-section-gap">
        @if ($isMySchedule)
            <x-ui.page-header
                :title="__('My Schedule')"
                :subtitle="__('Your upcoming shifts. Acknowledge each week once you\'ve reviewed it.')">
            </x-ui.page-header>
        @else
            <x-ui.page-header
                :title="__('Roster')"
                :subtitle="__('Roster assignments pair employees with a shift and policy group over a date range. Attendance days resolve against the assignment that covers their date.')">
                <x-slot name="help">
                    {{ __('Each row is one employee-and-period pairing. Delete to remove the assignment; create a new one to extend or replace it. Overlapping ranges per employee are blocked at save time.') }}
                </x-slot>
            </x-ui.page-header>
        @endif

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

        @include('people-attendance::livewire.people.attendance.partials.rosters-list')
    </div>
</div>
