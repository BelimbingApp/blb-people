<?php

use App\Modules\People\Settings\Livewire\Index;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'authz:people.settings.view'])
    ->get('people/settings', Index::class)
    ->name('people.settings.index');
