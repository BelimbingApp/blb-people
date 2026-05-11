<?php

namespace App\Modules\People\Payroll\Services;

use App\Base\Pdf\Jobs\RenderPdfJob;
use App\Modules\People\Payroll\Models\PayrollRun;
use App\Modules\People\Payroll\Models\PayrollRunParticipant;

class PayrollPdfReportJobFactory
{
    public function __construct(
        private readonly PayrollPayslipBuilder $payslips,
        private readonly PayrollSummaryReportBuilder $summaries,
        private readonly PayrollStatutoryContributionReportBuilder $statutoryContributions,
        private readonly PayrollEmployerCostReportBuilder $employerCosts,
        private readonly PayrollLockAuditReportBuilder $lockAudits,
    ) {}

    public function payslip(PayrollRunParticipant $participant, ?int $actorUserId = null, ?string $password = null): RenderPdfJob
    {
        $participant->loadMissing('run');

        return new RenderPdfJob(
            view: 'pdf.payroll.payslip',
            data: ['payslip' => $this->payslips->build($participant)],
            actorUserId: $actorUserId,
            templateVersion: 'payroll-payslip@v1',
            dataVersion: $this->participantDataVersion($participant),
            password: $password,
            renderMode: RenderPdfJob::MODE_INLINE,
            metadata: [
                'report_type' => 'payslip',
                'payroll_run_id' => $participant->payroll_run_id,
                'payroll_run_participant_id' => $participant->id,
                'employee_id' => $participant->employee_id,
            ],
        );
    }

    public function payrollSummary(PayrollRun $run, ?int $actorUserId = null): RenderPdfJob
    {
        return $this->runReportJob(
            run: $run,
            actorUserId: $actorUserId,
            view: 'pdf.payroll.payroll-summary',
            reportType: 'payroll_summary',
            templateVersion: 'payroll-summary@v1',
            report: $this->summaries->build($run),
        );
    }

    public function statutoryContributions(PayrollRun $run, ?int $actorUserId = null): RenderPdfJob
    {
        return $this->runReportJob(
            run: $run,
            actorUserId: $actorUserId,
            view: 'pdf.payroll.employee-statutory-contribution',
            reportType: 'employee_statutory_contribution',
            templateVersion: 'employee-statutory-contribution@v1',
            report: $this->statutoryContributions->build($run),
        );
    }

    public function employerCost(PayrollRun $run, ?int $actorUserId = null): RenderPdfJob
    {
        return $this->runReportJob(
            run: $run,
            actorUserId: $actorUserId,
            view: 'pdf.payroll.employer-cost',
            reportType: 'employer_cost',
            templateVersion: 'employer-cost@v1',
            report: $this->employerCosts->build($run),
        );
    }

    public function lockAudit(PayrollRun $run, ?int $actorUserId = null): RenderPdfJob
    {
        return $this->runReportJob(
            run: $run,
            actorUserId: $actorUserId,
            view: 'pdf.payroll.lock-audit',
            reportType: 'payroll_lock_audit',
            templateVersion: 'payroll-lock-audit@v1',
            report: $this->lockAudits->build($run),
        );
    }

    /**
     * @param  array<string, mixed>  $report
     */
    private function runReportJob(
        PayrollRun $run,
        ?int $actorUserId,
        string $view,
        string $reportType,
        string $templateVersion,
        array $report,
    ): RenderPdfJob {
        return new RenderPdfJob(
            view: $view,
            data: ['report' => $report],
            actorUserId: $actorUserId,
            templateVersion: $templateVersion,
            dataVersion: $this->runDataVersion($run),
            renderMode: RenderPdfJob::MODE_INLINE,
            metadata: [
                'report_type' => $reportType,
                'payroll_run_id' => $run->id,
            ],
        );
    }

    private function runDataVersion(PayrollRun $run): string
    {
        return 'payroll_run_id='.$run->id.';status='.$run->status.';updated_at='.(string) $run->updated_at?->getTimestamp();
    }

    private function participantDataVersion(PayrollRunParticipant $participant): string
    {
        return 'payroll_run_participant_id='.$participant->id.';payroll_run_id='.$participant->payroll_run_id.';updated_at='.(string) $participant->updated_at?->getTimestamp();
    }
}
