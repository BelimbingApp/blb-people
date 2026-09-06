<?php

namespace App\Domains\People\Performance;

use App\Domains\People\Organisation\Contracts\ContributesOrganisationRecordDetail;
use App\Domains\People\Performance\Console\Commands\OverdueReviewsCommand;
use App\Domains\People\Performance\Services\OrganisationPerformanceDetail;
use Illuminate\Support\ServiceProvider as BaseServiceProvider;

final class ServiceProvider extends BaseServiceProvider
{
    public function register(): void
    {
        $this->app->bind(ContributesOrganisationRecordDetail::class, OrganisationPerformanceDetail::class);

        if ($this->app->runningInConsole()) {
            $this->commands([
                OverdueReviewsCommand::class,
            ]);
        }
    }

    public function boot(): void
    {
        $this->loadViewsFrom(__DIR__.'/Views', 'people');
    }
}
