<?php

namespace App\Domains\People\Organisation;

use App\Domains\People\Organisation\Contracts\ReadsOrganisationExplorer;
use App\Domains\People\Organisation\Services\NativeOrganisationExplorer;
use App\Domains\People\Organisation\Services\PositionSubjectExporter;
use App\Domains\PeopleConnector\Connector\Contracts\ExportsSupplementalSubjectRecords;
use Illuminate\Support\ServiceProvider as BaseServiceProvider;

final class ServiceProvider extends BaseServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/Config/organisation.php', 'people-organisation');
        $this->app->bind(ReadsOrganisationExplorer::class, NativeOrganisationExplorer::class);

        if (interface_exists(ExportsSupplementalSubjectRecords::class)) {
            $this->app->singleton(PositionSubjectExporter::class);
            $this->app->tag([PositionSubjectExporter::class], ExportsSupplementalSubjectRecords::class);
        }
    }

    public function boot(): void
    {
        $this->loadViewsFrom(__DIR__.'/Views', 'people');
    }
}
