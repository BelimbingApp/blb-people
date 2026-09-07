<?php

namespace App\Domains\People\Organisation;

use App\Domains\People\Organisation\Contracts\ReadsOrganisationExplorer;
use App\Domains\People\Organisation\Services\NativeOrganisationExplorer;
use Illuminate\Support\ServiceProvider as BaseServiceProvider;

final class ServiceProvider extends BaseServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/Config/organisation.php', 'people-organisation');
        $this->app->bind(ReadsOrganisationExplorer::class, NativeOrganisationExplorer::class);
    }

    public function boot(): void
    {
        $this->loadViewsFrom(__DIR__.'/Views', 'people');
    }
}
