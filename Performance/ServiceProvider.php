<?php

namespace App\Domains\People\Performance;

use App\Domains\People\Organisation\Contracts\ContributesOrganisationRecordDetail;
use App\Domains\People\Performance\Console\Commands\CutoverCheckCommand;
use App\Domains\People\Performance\Console\Commands\OverdueReviewsCommand;
use App\Domains\People\Performance\Services\OrganisationPerformanceDetail;
use App\Domains\People\Performance\Services\PerformanceSubjectExporter;
use App\Domains\PeopleConnector\Connector\Contracts\ExportsSupplementalSubjectRecords;
use Illuminate\Support\ServiceProvider as BaseServiceProvider;

final class ServiceProvider extends BaseServiceProvider
{
    public function register(): void
    {
        $this->app->bind(ContributesOrganisationRecordDetail::class, OrganisationPerformanceDetail::class);

        if (interface_exists(ExportsSupplementalSubjectRecords::class)) {
            $this->app->singleton(PerformanceSubjectExporter::class);
            $this->app->tag([PerformanceSubjectExporter::class], ExportsSupplementalSubjectRecords::class);
        }

        if ($this->app->runningInConsole()) {
            $this->commands([
                CutoverCheckCommand::class,
                OverdueReviewsCommand::class,
            ]);
        }
    }

    public function boot(): void
    {
        $this->loadViewsFrom(__DIR__.'/Views', 'people');
    }
}
