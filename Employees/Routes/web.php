<?php

use App\Modules\People\Employees\Http\Controllers\EmployeeWorkbenchExportController;
use App\Modules\People\Employees\Livewire\Index;
use App\Modules\People\Employees\Livewire\Show;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth'])->group(function () {
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
