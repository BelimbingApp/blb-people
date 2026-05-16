<?php

namespace App\Modules\People\Payroll;

use App\Base\Pdf\Events\PdfArtifactRendered;
use App\Modules\People\Attendance\Events\AttendanceAllowanceMaterialized;
use App\Modules\People\Attendance\Events\AttendanceOvertimeApproved;
use App\Modules\People\Payroll\Console\Commands\MaterializePendingContributionsCommand;
use App\Modules\People\Payroll\CountryPacks\Malaysia\MalaysiaPayrollCountryPack;
use App\Modules\People\Payroll\Listeners\RecordAttendanceAllowanceContribution;
use App\Modules\People\Payroll\Listeners\RecordAttendanceOvertimeContribution;
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
        Event::listen(AttendanceOvertimeApproved::class, RecordAttendanceOvertimeContribution::class);
        Event::listen(AttendanceAllowanceMaterialized::class, RecordAttendanceAllowanceContribution::class);

        if ($this->app->runningInConsole()) {
            $this->commands([MaterializePendingContributionsCommand::class]);
        }
    }
}
