<?php

use App\Modules\People\Claim\Http\Controllers\ClaimOperationsExportController;
use App\Modules\People\Claim\Livewire\Index;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth'])->group(function (): void {
    Route::get('people/claims', Index::class)
        ->middleware('authz:people.claim.view')
        ->defaults('surface', 'my')
        ->name('people.claim.index');

    Route::get('people/claims/approvals', Index::class)
        ->middleware('authz:people.claim.approve')
        ->defaults('surface', 'approvals')
        ->name('people.claim.approvals');

    Route::middleware('authz:people.claim.manage')->group(function (): void {
        Route::get('people/claims/operations', Index::class)
            ->defaults('surface', 'operations')
            ->name('people.claim.operations');

        Route::get('people/claims/operations/export.csv', ClaimOperationsExportController::class)
            ->name('people.claim.operations.export.csv');

        Route::get('people/claims/settings', Index::class)
            ->defaults('surface', 'settings')
            ->defaults('section', 'categories')
            ->name('people.claim.settings');

        Route::get('people/claims/settings/types', Index::class)
            ->defaults('surface', 'settings')
            ->defaults('section', 'types')
            ->name('people.claim.settings.types');

        Route::get('people/claims/settings/policies', Index::class)
            ->defaults('surface', 'settings')
            ->defaults('section', 'policies')
            ->name('people.claim.settings.policies');

        Route::get('people/claims/settings/assignments', Index::class)
            ->defaults('surface', 'settings')
            ->defaults('section', 'assignments')
            ->name('people.claim.settings.assignments');

        Route::get('people/claims/settings/contexts', Index::class)
            ->defaults('surface', 'settings')
            ->defaults('section', 'contexts')
            ->name('people.claim.settings.contexts');
    });
});
