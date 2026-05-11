<?php

namespace App\Modules\People\Payroll;

use App\Modules\People\Payroll\CountryPacks\Malaysia\MalaysiaPayrollCountryPack;
use App\Modules\People\Payroll\Services\PayrollCountryPackRegistry;
use Illuminate\Support\ServiceProvider as BaseServiceProvider;

class ServiceProvider extends BaseServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(PayrollCountryPackRegistry::class);
        $this->app->singleton(MalaysiaPayrollCountryPack::class);
    }

    public function boot(): void
    {
        $this->app
            ->make(PayrollCountryPackRegistry::class)
            ->register($this->app->make(MalaysiaPayrollCountryPack::class));
    }
}
