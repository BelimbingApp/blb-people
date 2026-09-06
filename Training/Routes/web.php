<?php

use App\Domains\People\Training\Http\Middleware\AuthorizeTrainingAudience;
use App\Domains\People\Training\Livewire\Catalog\Index as CatalogIndex;
use App\Domains\People\Training\Livewire\Event\Index;
use App\Domains\People\Training\Livewire\HrGovernance\Index as HrGovernanceIndex;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth'])->group(function (): void {
    Route::get('people/training-catalog', CatalogIndex::class)
        ->middleware('authz:people.training.event.view', AuthorizeTrainingAudience::class)
        ->name('people.training.catalog.index');

    Route::get('people/training-events', Index::class)
        ->middleware('authz:people.training.event.view', AuthorizeTrainingAudience::class)
        ->name('people.training.events.index');

    // The HR audience is asserted inside the component (SkillAudience), so a
    // capability holder outside the HR audience is refused at mount, not listed.
    Route::get('people/hr-governance', HrGovernanceIndex::class)
        ->middleware('authz:'.HrGovernanceIndex::VIEW_CAPABILITY)
        ->name('people.hr-governance.index');
});
