<?php

namespace App\Modules\People\Payroll;

use App\Base\Pdf\Events\PdfArtifactRendered;
use App\Modules\People\Attendance\Events\AttendanceAllowanceMaterialized;
use App\Modules\People\Attendance\Events\AttendanceOvertimeApproved;
use App\Modules\People\Claim\Events\ClaimReimbursementQueued;
use App\Modules\People\Claim\Events\ClaimReimbursementReversed;
use App\Modules\People\Leave\Events\LeaveApplied;
use App\Modules\People\Leave\Events\LeaveEncashed;
use App\Modules\People\Payroll\Console\Commands\MaterializePendingContributionsCommand;
use App\Modules\People\Payroll\CountryPacks\Malaysia\MalaysiaPayrollCountryPack;
use App\Modules\People\Payroll\Listeners\RecordAttendanceAllowanceContribution;
use App\Modules\People\Payroll\Listeners\RecordAttendanceOvertimeContribution;
use App\Modules\People\Payroll\Listeners\RecordClaimReimbursement;
use App\Modules\People\Payroll\Listeners\RecordLeaveContribution;
use App\Modules\People\Payroll\Listeners\RecordLeaveEncashmentContribution;
use App\Modules\People\Payroll\Listeners\ReverseClaimReimbursement;
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
        $this->loadViewsFrom(__DIR__.'/Views', 'people-payroll');

        $this->app
            ->make(PayrollCountryPackRegistry::class)
            ->register($this->app->make(MalaysiaPayrollCountryPack::class));

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
