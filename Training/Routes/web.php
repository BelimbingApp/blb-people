<?php

use App\Domains\People\Training\Http\Middleware\AuthorizeTrainingAudience;
use App\Domains\People\Training\Livewire\Catalog\Index as CatalogIndex;
use App\Domains\People\Training\Livewire\Event\Index;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth'])->group(function (): void {
    Route::get('people/training-catalog', CatalogIndex::class)
        ->middleware('authz:people.training.event.view', AuthorizeTrainingAudience::class)
        ->name('people.training.catalog.index');

    Route::get('people/training-events', Index::class)
        ->middleware('authz:people.training.event.view', AuthorizeTrainingAudience::class)
        ->name('people.training.events.index');
});
