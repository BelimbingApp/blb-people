<?php

namespace App\Modules\People\Settings;

use Illuminate\Support\ServiceProvider as BaseServiceProvider;

class ServiceProvider extends BaseServiceProvider
{
    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        $this->loadViewsFrom(__DIR__.'/Views', 'people-settings');
    }
}
