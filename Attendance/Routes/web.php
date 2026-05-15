<?php

use App\Modules\People\Attendance\Livewire\AllowanceRules;
use App\Modules\People\Attendance\Livewire\Approvals;
use App\Modules\People\Attendance\Livewire\Locations;
use App\Modules\People\Attendance\Livewire\MyAttendance;
use App\Modules\People\Attendance\Livewire\Operations;
use App\Modules\People\Attendance\Livewire\PolicyGroups;
use App\Modules\People\Attendance\Livewire\PolicyGroupValidator;
use App\Modules\People\Attendance\Livewire\Rosters;
use App\Modules\People\Attendance\Livewire\ShiftTemplates;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth'])->group(function (): void {
    Route::get('people/attendance', MyAttendance::class)
        ->middleware('authz:people.attendance.view')
        ->name('people.attendance.index');

    Route::get('people/attendance/approvals', Approvals::class)
        ->middleware('authz:people.attendance.approve')
        ->name('people.attendance.approvals');

    Route::middleware('authz:people.attendance.manage')->group(function (): void {
        Route::get('people/attendance/operations', Operations::class)
            ->name('people.attendance.operations');

        Route::get('people/attendance/policy-groups', PolicyGroups::class)
            ->name('people.attendance.policy-groups');

        Route::get('people/attendance/policy-groups/validator', PolicyGroupValidator::class)
            ->name('people.attendance.policy-groups.validator');

        Route::get('people/attendance/shifts', ShiftTemplates::class)
            ->name('people.attendance.shifts');

        Route::get('people/attendance/rosters', Rosters::class)
            ->name('people.attendance.rosters');

        Route::get('people/attendance/allowance-rules', AllowanceRules::class)
            ->name('people.attendance.allowance-rules');

        Route::get('people/attendance/clocking-locations', Locations::class)
            ->name('people.attendance.clocking-locations');
    });
});
