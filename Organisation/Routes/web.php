<?php

use App\Domains\People\Organisation\Livewire\Explorer\Index;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth'])->group(function (): void {
    Route::get('people/organisation', Index::class)
        ->middleware('authz:people.organisation.structure.view')
        ->name('people.organisation.explorer.index');
});
