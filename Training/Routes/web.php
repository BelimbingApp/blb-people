<?php

use App\Domains\People\Training\Http\Controllers\TrainingPassportDocumentController;
use App\Domains\People\Training\Http\Middleware\AuthorizeTrainingAudience;
use App\Domains\People\Training\Livewire\Budget\Index as BudgetIndex;
use App\Domains\People\Training\Livewire\Calendar\Index as CalendarIndex;
use App\Domains\People\Training\Livewire\Catalog\Index as CatalogIndex;
use App\Domains\People\Training\Livewire\Effectiveness\Index as EffectivenessIndex;
use App\Domains\People\Training\Livewire\EffectivenessAggregate\Index as EffectivenessAggregateIndex;
use App\Domains\People\Training\Livewire\Evaluation\Index as EvaluationIndex;
use App\Domains\People\Training\Livewire\Event\Index;
use App\Domains\People\Training\Livewire\Evidence\Index as EvidenceIndex;
use App\Domains\People\Training\Livewire\HrGovernance\Index as HrGovernanceIndex;
use App\Domains\People\Training\Livewire\Migration\Index as MigrationIndex;
use App\Domains\People\Training\Livewire\Request\Index as RequestIndex;
use App\Domains\People\Training\Livewire\Requests\Register as RequestsRegister;
use App\Domains\People\Training\Livewire\TeamPassports;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth'])->group(function (): void {
    Route::get('people/training/team-passports/{employeeId?}', TeamPassports::class)
        ->middleware('authz:'.TeamPassports::VIEW_CAPABILITY)
        ->name('people.training.team-passports');

    // Download of a generated passport PDF (0014-a). The capability gets a
    // caller to the door; the store decides whether this document is theirs
    // (the employee it describes, or HR of the company) and still retained.
    Route::get('people/training/passport/documents/{documentId}', TrainingPassportDocumentController::class)
        ->where('documentId', '[0-9]+')
        ->middleware('authz:people.training.passport.view')
        ->name('people.training.passport.document');

    Route::get('people/training-catalog', CatalogIndex::class)
        ->middleware('authz:people.training.event.view', AuthorizeTrainingAudience::class)
        ->name('people.training.catalog.index');

    Route::get('people/training-events', Index::class)
        ->middleware('authz:people.training.event.view', AuthorizeTrainingAudience::class)
        ->name('people.training.events.index');

    Route::get('people/training-calendar', CalendarIndex::class)
        ->middleware('authz:people.training.calendar.view', AuthorizeTrainingAudience::class.':people.training.calendar.view')
        ->name('people.training.calendar');

    // The HR audience is asserted inside the component (SkillAudience), so a
    // capability holder outside the HR audience is refused at mount, not listed.
    Route::get('people/hr-governance', HrGovernanceIndex::class)
        ->middleware('authz:'.HrGovernanceIndex::VIEW_CAPABILITY)
        ->name('people.hr-governance.index');

    // Employee (self) and HOD (department) request page; the audience and
    // the requestor are asserted inside the component (SkillAudience).
    // HR register of every request in the company (0010-c); the HR audience
    // is asserted inside the component.
    Route::get('people/training/requests', RequestsRegister::class)
        ->middleware('authz:'.RequestsRegister::VIEW_CAPABILITY)
        ->name('people.training.requests.register');

    Route::get('people/training-requests', RequestIndex::class)
        ->middleware('authz:'.RequestIndex::CAPABILITY)
        ->name('people.training.requests.index');

    Route::get('people/training-evidence', EvidenceIndex::class)
        ->middleware('authz:'.EvidenceIndex::CAPABILITY)
        ->name('people.training.evidence.index');

    // HR sets the allocation; a HOD reads their own department's position.
    // Both are the same page and the same capability to reach it — only
    // people.training.budget.manage decides who may change an amount.
    Route::get('people/training/budget', BudgetIndex::class)
        ->middleware('authz:'.BudgetIndex::VIEW_CAPABILITY)
        ->name('people.training.budget.index');

    // The HOD's own 30/60/90-day questions. The department check lives in the
    // service, so this capability opens the page, not the answers on it.
    Route::get('people/training-effectiveness', EffectivenessIndex::class)
        ->middleware('authz:'.EffectivenessIndex::VIEW_CAPABILITY)
        ->name('people.training.effectiveness.index');

    // HR's roll-up of the same answers the HOD form records.
    Route::get('people/training/effectiveness-summary', EffectivenessAggregateIndex::class)
        ->middleware('authz:'.EffectivenessAggregateIndex::VIEW_CAPABILITY)
        ->name('people.training.effectiveness.summary');

    // The signed migration source inventory (0015-a). HR and HOD read; only
    // people.training.migration.manage records, updates and signs, and the
    // store decides that, not the page.
    Route::get('people/training/migration-sources', MigrationIndex::class)
        ->middleware('authz:'.MigrationIndex::VIEW_CAPABILITY)
        ->name('people.training.migration.index');

    Route::get('people/training-evaluations', EvaluationIndex::class)
        ->middleware('authz:'.EvaluationIndex::CAPABILITY)
        ->name('people.training.evaluations.index');
});
