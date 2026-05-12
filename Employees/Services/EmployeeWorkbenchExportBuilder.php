<?php

namespace App\Modules\People\Employees\Services;

use App\Modules\Core\Employee\Models\Employee;

class EmployeeWorkbenchExportBuilder
{
    public function __construct(
        private readonly EmployeePayrollReadinessService $readiness,
    ) {}

    /**
     * @param  iterable<int, Employee>  $employees
     * @return array<string, mixed>
     */
    public function csv(iterable $employees): array
    {
        $headers = [
            'employee_number',
            'employee_name',
            'company',
            'status',
            'designation',
            'cost_center',
            'cost_center_source_code',
            'organization_unit',
            'organization_unit_source_code',
            'employment_group',
            'employment_group_source_code',
            'job_title',
            'job_title_source_code',
            'workforce_class',
            'workforce_class_source_code',
            'job_grade',
            'job_grade_source_code',
            'work_calendar',
            'work_calendar_source_code',
            'pay_basis',
            'account_access_status',
            'account_login_identifier',
            'payroll_readiness',
            'payroll_blockers',
            'statutory_country',
            'statutory_validation_messages',
            'bank_name',
            'bank_account_number',
        ];

        $rows = [];

        foreach ($employees as $employee) {
            $summary = $this->readiness->summarize($employee);

            $rows[] = [
                'employee_number' => $employee->employee_number,
                'employee_name' => $employee->full_name,
                'company' => (string) ($employee->company_name ?? $employee->company?->name ?? ''),
                'status' => $employee->status,
                'designation' => (string) ($employee->designation ?? ''),
                'cost_center' => (string) ($employee->cost_center_name ?? ''),
                'cost_center_source_code' => (string) ($employee->cost_center_source_code ?? ''),
                'organization_unit' => (string) ($employee->organization_unit_name ?? ''),
                'organization_unit_source_code' => (string) ($employee->organization_unit_source_code ?? ''),
                'employment_group' => (string) ($employee->employment_group_name ?? ''),
                'employment_group_source_code' => (string) ($employee->employment_group_source_code ?? ''),
                'job_title' => (string) ($employee->job_title_name ?? ''),
                'job_title_source_code' => (string) ($employee->job_title_source_code ?? ''),
                'workforce_class' => (string) ($employee->workforce_class_name ?? ''),
                'workforce_class_source_code' => (string) ($employee->workforce_class_source_code ?? ''),
                'job_grade' => (string) ($employee->job_grade_name ?? ''),
                'job_grade_source_code' => (string) ($employee->job_grade_source_code ?? ''),
                'work_calendar' => (string) ($employee->work_calendar_name ?? ''),
                'work_calendar_source_code' => (string) ($employee->work_calendar_source_code ?? ''),
                'pay_basis' => (string) ($employee->work_profile_pay_basis ?? ''),
                'account_access_status' => (string) ($employee->portal_access_status ?? 'unprovisioned'),
                'account_login_identifier' => (string) ($employee->portal_access_login_identifier ?? ''),
                'payroll_readiness' => $summary['state'],
                'payroll_blockers' => implode('; ', array_map(
                    static fn (array $blocker): string => $blocker['label'],
                    $summary['blockers'],
                )),
                'statutory_country' => (string) ($summary['statutory_profile']['country_iso'] ?? ''),
                'statutory_validation_messages' => implode('; ', $summary['statutory_profile']['validation_messages'] ?? []),
                'bank_name' => (string) ($summary['bank']['bank_name'] ?? ''),
                'bank_account_number' => (string) ($summary['bank']['bank_account_number'] ?? ''),
            ];
        }

        return [
            'filename' => 'employee-workbench.csv',
            'content' => $this->csvContent($headers, $rows),
        ];
    }

    /**
     * @param  list<string>  $headers
     * @param  list<array<string, mixed>>  $rows
     */
    private function csvContent(array $headers, array $rows): string
    {
        $handle = fopen('php://temp', 'r+');
        fputcsv($handle, $headers);

        foreach ($rows as $row) {
            fputcsv($handle, array_map(
                static fn (string $header): string => (string) ($row[$header] ?? ''),
                $headers,
            ));
        }

        rewind($handle);
        $csv = stream_get_contents($handle);
        fclose($handle);

        return $csv === false ? '' : $csv;
    }
}
