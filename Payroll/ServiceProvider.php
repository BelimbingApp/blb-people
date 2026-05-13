<?php

namespace App\Modules\People\Payroll;

use App\Base\Pdf\Events\PdfArtifactRendered;
use App\Modules\People\Payroll\Console\Commands\MaterializePendingContributionsCommand;
use App\Modules\People\Payroll\CountryPacks\Malaysia\MalaysiaPayrollCountryPack;
use App\Modules\People\Payroll\Listeners\StorePayrollPdfArtifact;
use App\Modules\People\Payroll\Services\PayrollCountryPackRegistry;
use Illuminate\Support\Facades\Event;
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

        Event::listen(PdfArtifactRendered::class, StorePayrollPdfArtifact::class);

        if ($this->app->runningInConsole()) {
            $this->commands([MaterializePendingContributionsCommand::class]);
        }
    }
}
