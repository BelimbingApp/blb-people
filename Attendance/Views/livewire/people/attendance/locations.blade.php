<?php

use App\Modules\People\Attendance\Livewire\Locations;

/** @var Locations $this */
?>

<div>
    <x-slot name="title">{{ __('Clocking Locations') }}</x-slot>

    <div class="space-y-section-gap">
        <x-ui.page-header :title="__('Clocking Locations')" :subtitle="__('Maintain geofences and geofence groups used by clock-source policies.')">
            <x-slot name="help">
                {{ __('Geofences define accept regions for web and mobile clock events. Group them so a clock-source policy can reference multiple sites without listing each one.') }}
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

        @include('people-attendance::livewire.people.attendance.partials.locations-counts')
    </div>
</div>
