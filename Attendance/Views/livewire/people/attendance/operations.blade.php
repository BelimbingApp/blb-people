<?php

use App\Modules\People\Attendance\Livewire\Operations;

/** @var Operations $this */
?>

<div>
    <x-slot name="title">{{ __('Attendance Operations') }}</x-slot>

    <div class="space-y-section-gap">
        <x-ui.page-header :title="__('Attendance Operations')" :subtitle="__('Review timecards, absenteeism batches, clock events, and payroll handoff readiness.')">
            <x-slot name="help">
                {{ __('Finalize attendance days to lock their worked-minutes calculation; lock days to freeze them for payroll. Finalized days still allow correction; locked days do not.') }}
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

        @include('people-attendance::livewire.people.attendance.partials.summary-cards')
        @include('people-attendance::livewire.people.attendance.partials.attendance-days-card')
        @include('people-attendance::livewire.people.attendance.partials.operations-extras')
    </div>
</div>
