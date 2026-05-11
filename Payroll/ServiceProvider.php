<?php

namespace App\Modules\People\Payroll;

use App\Modules\People\Payroll\Services\PayrollCountryPackRegistry;
use Illuminate\Support\ServiceProvider as BaseServiceProvider;

class ServiceProvider extends BaseServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(PayrollCountryPackRegistry::class);
    }
}
