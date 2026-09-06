<?php

use App\Domains\People\Employees\Http\Controllers\EmployeeWorkbenchExportController;
use App\Domains\People\Employees\Livewire\Index;
use App\Domains\People\Employees\Livewire\MyStanding;
use App\Domains\People\Employees\Livewire\Show;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth'])->group(function () {
    Route::get('people/my-standing', MyStanding::class)
        ->middleware('authz:people.skill.assessment.view')
        ->name('people.employee-standing.show');

    Route::get('people/employees', Index::class)
        ->middleware('authz:people.employee.list')
        ->name('people.employees.index');

    Route::get('people/employees/export.csv', EmployeeWorkbenchExportController::class)
        ->middleware('authz:people.employee.list')
        ->name('people.employees.export.csv');

    Route::get('people/employees/{employee}', Show::class)
        ->middleware('authz:people.employee.view')
        ->name('people.employees.show');
});
