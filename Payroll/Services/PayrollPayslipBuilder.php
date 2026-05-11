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
        $lines = $participant->resultLines
            ->sortBy(fn (PayrollResultLine $line): string => $this->lineSortKey($line))
            ->values();
        $employerContributions = $this->sumLines($lines, PayrollResultLine::TYPE_EMPLOYER_CONTRIBUTION);
        $employerLevies = $this->sumLines($lines, PayrollResultLine::TYPE_EMPLOYER_LEVY);

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
                'employer_contributions' => $employerContributions,
                'employer_levies' => $employerLevies,
                'total_employer_cost' => $this->moneyString(
                    $this->moneyUnits($participant->gross_pay)
                    + $this->moneyUnits($participant->total_reimbursements)
                    + $this->moneyUnits($employerContributions)
                    + $this->moneyUnits($employerLevies),
                ),
            ],
            'sections' => [
                'earnings' => $this->linesOfType($lines, PayrollResultLine::TYPE_EARNING),
                'employee_deductions' => $this->linesOfType($lines, PayrollResultLine::TYPE_EMPLOYEE_DEDUCTION),
                'employee_contributions' => $this->linesOfType($lines, PayrollResultLine::TYPE_EMPLOYEE_CONTRIBUTION),
                'taxes' => $this->linesOfType($lines, PayrollResultLine::TYPE_TAX),
                'reimbursements' => $this->linesOfType($lines, PayrollResultLine::TYPE_REIMBURSEMENT),
                'employer_contributions' => $this->linesOfType($lines, PayrollResultLine::TYPE_EMPLOYER_CONTRIBUTION),
                'employer_levies' => $this->linesOfType($lines, PayrollResultLine::TYPE_EMPLOYER_LEVY),
                'net_pay' => $this->linesOfType($lines, PayrollResultLine::TYPE_NET_PAY),
            ],
            'lines' => $lines->map(fn (PayrollResultLine $line): array => $this->linePayload($line))->all(),
        ];
    }

    private function linePayload(PayrollResultLine $line): array
    {
        return [
            'type' => $line->line_type,
            'code' => $line->code,
            'label' => $line->label,
            'amount' => $line->amount,
            'source_rule' => $line->source_rule,
            'explanation' => $line->explanation ?? [],
        ];
    }

    private function linesOfType($lines, string $lineType): array
    {
        return $lines
            ->where('line_type', $lineType)
            ->map(fn (PayrollResultLine $line): array => $this->linePayload($line))
            ->values()
            ->all();
    }

    private function sumLines($lines, string $lineType): string
    {
        return $this->moneyString($lines
            ->where('line_type', $lineType)
            ->sum(fn (PayrollResultLine $line): int => $this->moneyUnits($line->amount)));
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

    private function moneyUnits(string|int|float|null $amount): int
    {
        $normalized = trim((string) ($amount ?? '0'));
        $negative = str_starts_with($normalized, '-');
        $normalized = ltrim($normalized, '+-');
        [$whole, $fraction] = array_pad(explode('.', $normalized, 2), 2, '');

        $units = ((int) $whole * 10000) + (int) str_pad(substr($fraction, 0, 4), 4, '0');

        return $negative ? -$units : $units;
    }

    private function moneyString(int $units): string
    {
        $sign = $units < 0 ? '-' : '';
        $absolute = abs($units);

        return sprintf('%s%d.%04d', $sign, intdiv($absolute, 10000), $absolute % 10000);
    }
}
