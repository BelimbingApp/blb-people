<?php

namespace App\Modules\People\Attendance;

use App\Modules\People\Attendance\Console\Commands\PolicySimulateCommand;
use App\Modules\People\Attendance\Console\Commands\PolicyValidateCommand;
use App\Modules\People\Attendance\Console\Commands\RosterCommand;
use App\Modules\People\Attendance\Services\AttendanceDayProjectionService;
use App\Modules\People\Attendance\Services\AttendancePolicyGroupResolver;
use App\Modules\People\Attendance\Services\AttendancePolicySimulationService;
use App\Modules\People\Attendance\Services\AttendancePolicyValidationService;
use Illuminate\Support\ServiceProvider as BaseServiceProvider;

class ServiceProvider extends BaseServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(AttendancePolicyGroupResolver::class);
        $this->app->singleton(AttendanceDayProjectionService::class);
        $this->app->singleton(AttendancePolicyValidationService::class);
        $this->app->singleton(AttendancePolicySimulationService::class);
    }

    public function boot(): void
    {
        $this->loadViewsFrom(__DIR__.'/Views', 'people-attendance');

        if ($this->app->runningInConsole()) {
            $this->commands([
                PolicyValidateCommand::class,
                PolicySimulateCommand::class,
                RosterCommand::class,
            ]);
        }
    }
}
