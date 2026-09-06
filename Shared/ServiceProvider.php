<?php

namespace App\Domains\People\Shared;

use App\Domains\People\Shared\Livewire\AsOfDatePicker;
use App\Domains\People\Shared\View\Components\RequirementVersionPill;
use App\Domains\People\Shared\View\Components\StandingBadge;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\ServiceProvider as BaseServiceProvider;
use Livewire\Livewire;

class ServiceProvider extends BaseServiceProvider
{
    public function boot(): void
    {
        $this->loadViewsFrom(__DIR__.'/Views', 'people');

        Blade::component(StandingBadge::class, 'people-standing-badge');
        Blade::component(RequirementVersionPill::class, 'people-requirement-version-pill');

        Livewire::component('people-shared.as-of-date-picker', AsOfDatePicker::class);
    }
}
