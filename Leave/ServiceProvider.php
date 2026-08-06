<?php

namespace App\Domains\People\Leave;

use App\Domains\People\Leave\Console\Commands\CarryForwardCommand;
use App\Domains\People\Leave\Console\Commands\ExpireReplacementCommand;
use App\Domains\People\Leave\Console\Commands\SeedSbgLeavePackCommand;
use App\Domains\People\Leave\Contracts\RoutesLeaveApprovals;
use App\Domains\People\Leave\CountryPacks\Malaysia\MalaysiaLeaveCountryPack;
use App\Domains\People\Leave\Services\LeaveCountryPackRegistry;
use App\Domains\People\Leave\Services\NullLeaveApprovalRouter;
use Illuminate\Support\ServiceProvider as BaseServiceProvider;

class ServiceProvider extends BaseServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(LeaveCountryPackRegistry::class);
        $this->app->singleton(MalaysiaLeaveCountryPack::class);
        $this->app->bind(RoutesLeaveApprovals::class, NullLeaveApprovalRouter::class);
    }

    public function boot(): void
    {
        $this->loadViewsFrom(__DIR__.'/Views', 'people-leave');

        $this->app
            ->make(LeaveCountryPackRegistry::class)
            ->register($this->app->make(MalaysiaLeaveCountryPack::class));

        if ($this->app->runningInConsole()) {
            $this->commands([
                CarryForwardCommand::class,
                ExpireReplacementCommand::class,
                SeedSbgLeavePackCommand::class,
            ]);
        }
    }
}
