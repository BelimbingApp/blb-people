<?php

use App\Modules\People\Attendance\Livewire\Index;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth'])->group(function (): void {
    Route::get('people/attendance', Index::class)
        ->middleware('authz:people.attendance.view')
        ->defaults('surface', 'my')
        ->name('people.attendance.index');

    Route::get('people/attendance/approvals', Index::class)
        ->middleware('authz:people.attendance.approve')
        ->defaults('surface', 'approvals')
        ->name('people.attendance.approvals');

    Route::middleware('authz:people.attendance.manage')->group(function (): void {
        Route::get('people/attendance/operations', Index::class)
            ->defaults('surface', 'operations')
            ->name('people.attendance.operations');

        Route::get('people/attendance/policy-studio', Index::class)
            ->defaults('surface', 'settings')
            ->defaults('section', 'policies')
            ->defaults('mode', 'library')
            ->name('people.attendance.policy-studio.library');

        Route::get('people/attendance/policy-studio/builder', Index::class)
            ->defaults('surface', 'settings')
            ->defaults('section', 'policies')
            ->defaults('mode', 'builder')
            ->name('people.attendance.policy-studio.builder');

        Route::get('people/attendance/policy-studio/validator', Index::class)
            ->defaults('surface', 'settings')
            ->defaults('section', 'policies')
            ->defaults('mode', 'simulate')
            ->name('people.attendance.policy-studio.validator');

        Route::get('people/attendance/shifts', Index::class)
            ->defaults('surface', 'settings')
            ->defaults('section', 'shifts')
            ->name('people.attendance.shifts');

        Route::get('people/attendance/rosters', Index::class)
            ->defaults('surface', 'settings')
            ->defaults('section', 'rosters')
            ->name('people.attendance.rosters');

        Route::get('people/attendance/allowance-rules', Index::class)
            ->defaults('surface', 'settings')
            ->defaults('section', 'allowances')
            ->name('people.attendance.allowance-rules');

        Route::get('people/attendance/clocking-locations', Index::class)
            ->defaults('surface', 'settings')
            ->defaults('section', 'locations')
            ->name('people.attendance.clocking-locations');
    });
});
