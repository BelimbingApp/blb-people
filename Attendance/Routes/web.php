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

        Route::get('people/attendance/settings', Index::class)
            ->defaults('surface', 'settings')
            ->name('people.attendance.settings');
    });
});
