<?php

namespace App\Modules\People\Attendance;

use App\Modules\People\Attendance\Services\AttendanceDayProjectionService;
use App\Modules\People\Attendance\Services\AttendancePolicyGroupResolver;
use Illuminate\Support\ServiceProvider as BaseServiceProvider;

class ServiceProvider extends BaseServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(AttendancePolicyGroupResolver::class);
        $this->app->singleton(AttendanceDayProjectionService::class);
    }
}
