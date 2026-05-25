<?php

namespace App\Modules\People\Leave;

use App\Modules\People\Leave\Console\Commands\CarryForwardCommand;
use App\Modules\People\Leave\Console\Commands\ExpireReplacementCommand;
use App\Modules\People\Leave\Console\Commands\SeedSbgLeavePackCommand;
use App\Modules\People\Leave\Contracts\RoutesLeaveApprovals;
use App\Modules\People\Leave\CountryPacks\Malaysia\MalaysiaLeaveCountryPack;
use App\Modules\People\Leave\Services\LeaveCountryPackRegistry;
use App\Modules\People\Leave\Services\NullLeaveApprovalRouter;
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
