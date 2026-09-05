<?php

namespace App\Domains\People\Organisation;

use App\Domains\People\Organisation\Contracts\ReadsOrganisationExplorer;
use App\Domains\People\Organisation\Services\NativeOrganisationExplorer;
use Illuminate\Support\ServiceProvider as BaseServiceProvider;

final class ServiceProvider extends BaseServiceProvider
{
    public function register(): void
    {
        $this->app->bind(ReadsOrganisationExplorer::class, NativeOrganisationExplorer::class);
    }
}
