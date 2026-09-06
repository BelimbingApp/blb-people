<?php

use App\Domains\People\Skills\Livewire\Assessment\Matrix;
use App\Domains\People\Skills\Livewire\Catalog\Index;
use App\Domains\People\Skills\Livewire\DevelopmentAction\Index as DevelopmentActionIndex;
use App\Domains\People\Skills\Livewire\Planning\Index as HodPlanningIndex;
use App\Domains\People\Skills\Livewire\RequirementProfile\Show as RequirementProfileShow;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth'])->group(function (): void {
    Route::get('people/skills', Index::class)
        ->middleware('authz:people.skill.catalog.view')
        ->name('people.skill.catalog.index');

    Route::get('people/skill-requirements/{profileId}', RequirementProfileShow::class)
        ->middleware('authz:people.skill.catalog.view')
        ->whereNumber('profileId')
        ->name('people.skill.requirement-profiles.show');

    Route::get('people/skill-assessments', Matrix::class)
        ->middleware('authz:people.skill.assessment.view')
        ->name('people.skill.assessment.matrix');

    Route::get('people/development-actions', DevelopmentActionIndex::class)
        ->middleware('authz:people.skill.development-action.view')
        ->name('people.skill.development-actions.index');

    Route::get('people/hod-planning', HodPlanningIndex::class)
        ->middleware('authz:people.skill.assessment.view')
        ->name('people.skill.planning.index');
});
