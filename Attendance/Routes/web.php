<?php

use App\Modules\People\Attendance\Livewire\Allowances;
use App\Modules\People\Attendance\Livewire\Approvals;
use App\Modules\People\Attendance\Livewire\Locations;
use App\Modules\People\Attendance\Livewire\MyAttendance;
use App\Modules\People\Attendance\Livewire\Operations;
use App\Modules\People\Attendance\Livewire\PolicyStudio\Builder as PolicyBuilder;
use App\Modules\People\Attendance\Livewire\PolicyStudio\Library as PolicyLibrary;
use App\Modules\People\Attendance\Livewire\PolicyStudio\Validator as PolicyValidator;
use App\Modules\People\Attendance\Livewire\Rosters;
use App\Modules\People\Attendance\Livewire\Shifts\Builder as ShiftBuilder;
use App\Modules\People\Attendance\Livewire\Shifts\Library as ShiftLibrary;
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

        Route::get('people/attendance/policy-studio', PolicyLibrary::class)
            ->name('people.attendance.policy-studio.library');

        Route::get('people/attendance/policy-studio/builder', PolicyBuilder::class)
            ->name('people.attendance.policy-studio.builder');

        Route::get('people/attendance/policy-studio/validator', PolicyValidator::class)
            ->name('people.attendance.policy-studio.validator');

        Route::get('people/attendance/shifts', ShiftBuilder::class)
            ->name('people.attendance.shifts');

        Route::get('people/attendance/shifts/library', ShiftLibrary::class)
            ->name('people.attendance.shift-library');

        Route::get('people/attendance/rosters', Rosters::class)
            ->name('people.attendance.rosters');

        Route::get('people/attendance/allowance-rules', Allowances::class)
            ->name('people.attendance.allowance-rules');

        Route::get('people/attendance/clocking-locations', Locations::class)
            ->name('people.attendance.clocking-locations');
    });
});
