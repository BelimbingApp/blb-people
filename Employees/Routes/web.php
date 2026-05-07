<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

use App\Modules\People\Employees\Livewire\Index;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'authz:people.employee.list'])
    ->get('people/employees', Index::class)
    ->name('people.employees.index');
