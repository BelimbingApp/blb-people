<?php

use App\Domains\People\Performance\Livewire\Reviews\Index as ReviewsIndex;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth'])->group(function (): void {
    // The capability gates the route; the component asserts it again so a
    // direct component render cannot skip it.
    Route::get('people/performance/reviews', ReviewsIndex::class)
        ->middleware('authz:'.ReviewsIndex::VIEW_CAPABILITY)
        ->name('people.performance.reviews.index');
});
