<?php
namespace App\Modules\People\Payroll\Services;

use App\Modules\People\Payroll\Models\PayrollResultLine;
use App\Modules\People\Payroll\Models\PayrollRunParticipant;

class PayrollPayslipBuilder
{
    /**
     * @return array<string, mixed>
     */
    public function build(PayrollRunParticipant $participant): array
    {
        $participant->loadMissing(['employee', 'run.period', 'resultLines']);

        return [
            'employee' => [
                'id' => $participant->employee->id,
                'number' => $participant->employee->employee_number,
                'name' => $participant->employee->displayName(),
            ],
            'period' => [
                'code' => $participant->run->period->code,
                'name' => $participant->run->period->name,
                'starts_on' => $participant->run->period->starts_on->toDateString(),
                'ends_on' => $participant->run->period->ends_on->toDateString(),
                'pay_date' => $participant->run->period->pay_date->toDateString(),
            ],
            'currency' => $participant->currency,
            'summary' => [
                'gross_pay' => $participant->gross_pay,
                'total_deductions' => $participant->total_deductions,
                'total_reimbursements' => $participant->total_reimbursements,
                'net_pay' => $participant->net_pay,
            ],
            'lines' => $participant->resultLines
                ->sortBy(fn (PayrollResultLine $line): string => $this->lineSortKey($line))
                ->values()
                ->map(fn (PayrollResultLine $line): array => [
                    'type' => $line->line_type,
                    'code' => $line->code,
                    'label' => $line->label,
                    'amount' => $line->amount,
                    'source_rule' => $line->source_rule,
                    'explanation' => $line->explanation ?? [],
                ])
                ->all(),
        ];
    }

    private function lineSortKey(PayrollResultLine $line): string
    {
        $rank = match ($line->line_type) {
            PayrollResultLine::TYPE_EARNING => '10',
            PayrollResultLine::TYPE_EMPLOYEE_CONTRIBUTION,
            PayrollResultLine::TYPE_EMPLOYEE_DEDUCTION,
            PayrollResultLine::TYPE_TAX => '20',
            PayrollResultLine::TYPE_REIMBURSEMENT => '30',
            PayrollResultLine::TYPE_EMPLOYER_CONTRIBUTION,
            PayrollResultLine::TYPE_EMPLOYER_LEVY => '40',
            PayrollResultLine::TYPE_NET_PAY => '90',
            default => '80',
        };

        return $rank.'-'.$line->id;
    }
}
