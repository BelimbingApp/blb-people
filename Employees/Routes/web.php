<?php
use App\Modules\People\Employees\Livewire\Index;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'authz:people.employee.list'])
    ->get('people/employees', Index::class)
    ->name('people.employees.index');
