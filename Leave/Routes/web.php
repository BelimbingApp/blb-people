<?php

use App\Modules\People\Leave\Livewire\Index;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth'])->group(function (): void {
    Route::get('people/leave', Index::class)
        ->middleware('authz:people.leave.view')
        ->defaults('surface', 'my')
        ->name('people.leave.index');

    Route::get('people/leave/approvals', Index::class)
        ->middleware('authz:people.leave.approve')
        ->defaults('surface', 'approvals')
        ->name('people.leave.approvals');

    Route::middleware('authz:people.leave.manage')->group(function (): void {
        Route::get('people/leave/settings', Index::class)
            ->defaults('surface', 'settings')
            ->defaults('section', 'types')
            ->name('people.leave.settings');

        Route::get('people/leave/settings/policies', Index::class)
            ->defaults('surface', 'settings')
            ->defaults('section', 'policies')
            ->name('people.leave.settings.policies');

        Route::get('people/leave/settings/assignments', Index::class)
            ->defaults('surface', 'settings')
            ->defaults('section', 'assignments')
            ->name('people.leave.settings.assignments');

        Route::get('people/leave/settings/balances', Index::class)
            ->defaults('surface', 'settings')
            ->defaults('section', 'balances')
            ->name('people.leave.settings.balances');

        Route::get('people/leave/settings/adjustments', Index::class)
            ->defaults('surface', 'settings')
            ->defaults('section', 'adjustments')
            ->name('people.leave.settings.adjustments');

        Route::get('people/leave/settings/carry-forward', Index::class)
            ->defaults('surface', 'settings')
            ->defaults('section', 'carry-forward')
            ->name('people.leave.settings.carry-forward');
    });
});
