<?php

namespace App\Modules\People\Employees;

use Illuminate\Support\ServiceProvider as BaseServiceProvider;

class ServiceProvider extends BaseServiceProvider
{
    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        $this->loadViewsFrom(__DIR__.'/Views', 'people-employees');
    }
}
