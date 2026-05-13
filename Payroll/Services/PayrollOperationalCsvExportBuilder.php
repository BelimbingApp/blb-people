<?php

namespace App\Modules\People\Payroll\Services;

use App\Modules\People\Payroll\Models\PayrollRun;

class PayrollOperationalCsvExportBuilder
{
    private const ZERO_AMOUNT = '0.0000';

    private const PAYROLL_SUMMARY_HEADERS = [
        'payroll_run_code',
        'period_code',
        'employee_number',
        'employee_name',
        'gross_pay',
        'employee_deductions',
        'employee_contributions',
        'taxes',
        'reimbursements',
        'net_pay',
    ];

    private const STATUTORY_CONTRIBUTION_HEADERS = [
        'payroll_run_code',
        'period_code',
        'employee_number',
        'employee_name',
        'line_type',
        'code',
        'label',
        'source_rule',
        'source_version',
        'amount',
    ];

    private const EMPLOYER_COST_HEADERS = [
        'payroll_run_code',
        'period_code',
        'employee_number',
        'employee_name',
        'gross_pay',
        'reimbursements',
        'employer_contributions',
        'employer_levies',
        'total_employer_cost',
    ];

    private const LOCK_AUDIT_HEADERS = [
        'payroll_run_code',
        'status',
        'locked',
        'occurred_at',
        'action',
        'user',
        'message',
    ];

    public function __construct(
        private readonly PayrollSummaryReportBuilder $summaries,
        private readonly PayrollStatutoryContributionReportBuilder $statutoryContributions,
        private readonly PayrollEmployerCostReportBuilder $employerCosts,
        private readonly PayrollLockAuditReportBuilder $lockAudits,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function payrollSummary(PayrollRun $run): array
    {
        $report = $this->summaries->build($run);
        $rows = collect($report['participants'] ?? [])
            ->map(fn (array $participant): array => [
                'payroll_run_code' => $report['run']['code'] ?? '',
                'period_code' => $report['run']['period'] ?? '',
                'employee_number' => $participant['employee']['number'] ?? '',
                'employee_name' => $participant['employee']['name'] ?? '',
                'gross_pay' => $participant['gross_pay'] ?? self::ZERO_AMOUNT,
                'employee_deductions' => $participant['employee_deductions'] ?? self::ZERO_AMOUNT,
                'employee_contributions' => $participant['employee_contributions'] ?? self::ZERO_AMOUNT,
                'taxes' => $participant['taxes'] ?? self::ZERO_AMOUNT,
                'reimbursements' => $participant['reimbursements'] ?? self::ZERO_AMOUNT,
                'net_pay' => $participant['net_pay'] ?? self::ZERO_AMOUNT,
            ])
            ->all();

        return $this->export('payroll-summary', $report['run']['code'] ?? $run->code, self::PAYROLL_SUMMARY_HEADERS, $rows);
    }

    /**
     * @return array<string, mixed>
     */
    public function statutoryContributions(PayrollRun $run): array
    {
        $report = $this->statutoryContributions->build($run);
        $rows = collect($report['participants'] ?? [])
            ->flatMap(fn (array $participant): array => collect($participant['lines'] ?? [])
                ->map(fn (array $line): array => [
                    'payroll_run_code' => $report['run']['code'] ?? '',
                    'period_code' => $report['run']['period'] ?? '',
                    'employee_number' => $participant['employee']['number'] ?? '',
                    'employee_name' => $participant['employee']['name'] ?? '',
                    'line_type' => $line['type'] ?? '',
                    'code' => $line['code'] ?? '',
                    'label' => $line['label'] ?? '',
                    'source_rule' => $line['source_rule'] ?? '',
                    'source_version' => $line['source_version'] ?? '',
                    'amount' => $line['amount'] ?? self::ZERO_AMOUNT,
                ])
                ->all())
            ->values()
            ->all();

        return $this->export('statutory-contributions', $report['run']['code'] ?? $run->code, self::STATUTORY_CONTRIBUTION_HEADERS, $rows);
    }

    /**
     * @return array<string, mixed>
     */
    public function employerCost(PayrollRun $run): array
    {
        $report = $this->employerCosts->build($run);
        $rows = collect($report['participants'] ?? [])
            ->map(fn (array $participant): array => [
                'payroll_run_code' => $report['run']['code'] ?? '',
                'period_code' => $report['run']['period'] ?? '',
                'employee_number' => $participant['employee']['number'] ?? '',
                'employee_name' => $participant['employee']['name'] ?? '',
                'gross_pay' => $participant['gross_pay'] ?? self::ZERO_AMOUNT,
                'reimbursements' => $participant['reimbursements'] ?? self::ZERO_AMOUNT,
                'employer_contributions' => $participant['employer_contributions'] ?? self::ZERO_AMOUNT,
                'employer_levies' => $participant['employer_levies'] ?? self::ZERO_AMOUNT,
                'total_employer_cost' => $participant['total_employer_cost'] ?? self::ZERO_AMOUNT,
            ])
            ->all();

        return $this->export('employer-cost', $report['run']['code'] ?? $run->code, self::EMPLOYER_COST_HEADERS, $rows);
    }

    /**
     * @return array<string, mixed>
     */
    public function lockAudit(PayrollRun $run): array
    {
        $report = $this->lockAudits->build($run);
        $rows = collect($report['audit_events'] ?? [])
            ->map(fn (array $event): array => [
                'payroll_run_code' => $report['run']['code'] ?? '',
                'status' => $report['run']['status'] ?? '',
                'locked' => ($report['lock_state']['is_locked'] ?? false) ? 'yes' : 'no',
                'occurred_at' => $event['occurred_at'] ?? '',
                'action' => $event['action'] ?? '',
                'user' => $event['user'] ?? '',
                'message' => $event['message'] ?? '',
            ])
            ->all();

        return $this->export('lock-audit', $report['run']['code'] ?? $run->code, self::LOCK_AUDIT_HEADERS, $rows);
    }

    /**
     * @param  list<string>  $headers
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, mixed>
     */
    private function export(string $type, string $runCode, array $headers, array $rows): array
    {
        return [
            'filename' => $type.'-'.$runCode.'.csv',
            'format' => 'csv',
            'report_type' => $type,
            'headers' => $headers,
            'rows' => $rows,
            'totals' => [
                'rows' => count($rows),
            ],
            'content' => $this->csv($headers, $rows),
        ];
    }

    /**
     * @param  list<string>  $headers
     * @param  list<array<string, mixed>>  $rows
     */
    private function csv(array $headers, array $rows): string
    {
        $handle = fopen('php://temp', 'r+');
        fputcsv($handle, $headers);

        foreach ($rows as $row) {
            fputcsv($handle, array_map(fn (string $header): string => (string) ($row[$header] ?? ''), $headers));
        }

        rewind($handle);
        $csv = stream_get_contents($handle);
        fclose($handle);

        return $csv === false ? '' : $csv;
    }
}
