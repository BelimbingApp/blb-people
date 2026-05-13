<?php

use App\Modules\People\Claim\Livewire\Index;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth'])->group(function (): void {
    Route::get('people/claims', Index::class)
        ->middleware('authz:people.claim.view')
        ->name('people.claim.index');
});
