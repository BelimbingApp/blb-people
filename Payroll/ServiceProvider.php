<?php

namespace App\Domains\People\Payroll;

use App\Base\Pdf\Events\PdfArtifactRendered;
use App\Domains\People\Attendance\Events\AttendanceAllowanceMaterialized;
use App\Domains\People\Attendance\Events\AttendanceOvertimeApproved;
use App\Domains\People\Claim\Events\ClaimReimbursementQueued;
use App\Domains\People\Claim\Events\ClaimReimbursementReversed;
use App\Domains\People\Leave\Events\LeaveApplied;
use App\Domains\People\Leave\Events\LeaveEncashed;
use App\Domains\People\Payroll\Console\Commands\MaterializePendingContributionsCommand;
use App\Domains\People\Payroll\CountryPacks\Malaysia\MalaysiaPayrollCountryPack;
use App\Domains\People\Payroll\Listeners\RecordAttendanceAllowanceContribution;
use App\Domains\People\Payroll\Listeners\RecordAttendanceOvertimeContribution;
use App\Domains\People\Payroll\Listeners\RecordClaimReimbursement;
use App\Domains\People\Payroll\Listeners\RecordLeaveContribution;
use App\Domains\People\Payroll\Listeners\RecordLeaveEncashmentContribution;
use App\Domains\People\Payroll\Listeners\ReverseClaimReimbursement;
use App\Domains\People\Payroll\Listeners\StorePayrollPdfArtifact;
use App\Domains\People\Payroll\Services\PayrollCountryPackDiscoveryService;
use App\Domains\People\Payroll\Services\PayrollCountryPackRegistry;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider as BaseServiceProvider;

class ServiceProvider extends BaseServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(PayrollCountryPackRegistry::class);
        $this->app->singleton(PayrollCountryPackDiscoveryService::class);
        $this->app->singleton(MalaysiaPayrollCountryPack::class);
    }

    public function boot(PayrollCountryPackDiscoveryService $countryPacks): void
    {
        $this->loadViewsFrom(__DIR__.'/Views', 'people-payroll');

        $countryPacks->discoverInto($this->app->make(PayrollCountryPackRegistry::class));

        Event::listen(PdfArtifactRendered::class, StorePayrollPdfArtifact::class);
        Event::listen(AttendanceOvertimeApproved::class, RecordAttendanceOvertimeContribution::class);
        Event::listen(AttendanceAllowanceMaterialized::class, RecordAttendanceAllowanceContribution::class);
        Event::listen(LeaveApplied::class, RecordLeaveContribution::class);
        Event::listen(LeaveEncashed::class, RecordLeaveEncashmentContribution::class);
        Event::listen(ClaimReimbursementQueued::class, RecordClaimReimbursement::class);
        Event::listen(ClaimReimbursementReversed::class, ReverseClaimReimbursement::class);

        if ($this->app->runningInConsole()) {
            $this->commands([MaterializePendingContributionsCommand::class]);
        }
    }
}
