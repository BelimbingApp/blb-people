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

    Route::get('people/leave/admin', Index::class)
        ->middleware('authz:people.leave.manage')
        ->defaults('surface', 'admin')
        ->name('people.leave.admin');
});
