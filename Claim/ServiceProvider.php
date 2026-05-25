<?php

namespace App\Modules\People\Claim;

use App\Modules\People\Claim\Console\Commands\PolicySimulateCommand;
use App\Modules\People\Claim\Console\Commands\PolicyValidateCommand;
use App\Modules\People\Claim\Services\ClaimCohortPredicateService;
use App\Modules\People\Claim\Services\ClaimPolicyEvaluationService;
use App\Modules\People\Claim\Services\ClaimPolicySimulationService;
use App\Modules\People\Claim\Services\ClaimPolicyValidationService;
use Illuminate\Support\ServiceProvider as BaseServiceProvider;

class ServiceProvider extends BaseServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ClaimPolicyEvaluationService::class);
        $this->app->singleton(ClaimPolicyValidationService::class);
        $this->app->singleton(ClaimPolicySimulationService::class);
        $this->app->singleton(ClaimCohortPredicateService::class);
    }

    public function boot(): void
    {
        $this->loadViewsFrom(__DIR__.'/Views', 'people-claim');

        if ($this->app->runningInConsole()) {
            $this->commands([
                PolicyValidateCommand::class,
                PolicySimulateCommand::class,
            ]);
        }
    }
}
