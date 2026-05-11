<?php

namespace App\Modules\People\Payroll\Services;

use App\Modules\People\Payroll\Models\PayrollResultLine;
use App\Modules\People\Payroll\Models\PayrollRun;
use App\Modules\People\Payroll\Models\PayrollRunParticipant;

class PayrollEmployerCostReportBuilder
{
    /**
     * @return array<string, mixed>
     */
    public function build(PayrollRun $run): array
    {
        $run->loadMissing(['calendar', 'period', 'participants.employee', 'participants.resultLines']);
        $participants = $run->participants
            ->map(fn (PayrollRunParticipant $participant): array => $this->participantPayload($participant))
            ->values()
            ->all();

        return [
            'run' => [
                'id' => $run->id,
                'code' => $run->code,
                'name' => $run->name,
                'status' => $run->status,
                'country_iso' => $run->calendar?->country_iso,
                'currency' => $run->currency,
                'period' => $run->period?->code,
                'pay_date' => $run->period?->pay_date?->toDateString(),
            ],
            'participants' => $participants,
            'totals' => [
                'gross_pay' => $this->sumPayload($participants, 'gross_pay'),
                'reimbursements' => $this->sumPayload($participants, 'reimbursements'),
                'employer_contributions' => $this->sumPayload($participants, 'employer_contributions'),
                'employer_levies' => $this->sumPayload($participants, 'employer_levies'),
                'total_employer_cost' => $this->sumPayload($participants, 'total_employer_cost'),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function participantPayload(PayrollRunParticipant $participant): array
    {
        $employerCostLines = $participant->resultLines
            ->filter(fn (PayrollResultLine $line): bool => in_array($line->line_type, [
                PayrollResultLine::TYPE_EMPLOYER_CONTRIBUTION,
                PayrollResultLine::TYPE_EMPLOYER_LEVY,
            ], true))
            ->sortBy('id')
            ->values();
        $employerContributions = $this->sumLines($employerCostLines, PayrollResultLine::TYPE_EMPLOYER_CONTRIBUTION);
        $employerLevies = $this->sumLines($employerCostLines, PayrollResultLine::TYPE_EMPLOYER_LEVY);

        return [
            'employee' => [
                'id' => $participant->employee->id,
                'number' => $participant->employee->employee_number,
                'name' => $participant->employee->displayName(),
            ],
            'gross_pay' => $participant->gross_pay,
            'reimbursements' => $participant->total_reimbursements,
            'employer_contributions' => $employerContributions,
            'employer_levies' => $employerLevies,
            'total_employer_cost' => $this->moneyString(
                $this->moneyUnits($participant->gross_pay)
                + $this->moneyUnits($participant->total_reimbursements)
                + $this->moneyUnits($employerContributions)
                + $this->moneyUnits($employerLevies),
            ),
            'lines' => $employerCostLines
                ->map(fn (PayrollResultLine $line): array => [
                    'type' => $line->line_type,
                    'code' => $line->code,
                    'label' => $line->label,
                    'amount' => $line->amount,
                    'source_rule' => $line->source_rule,
                    'source_version' => $line->source_version,
                    'explanation' => $line->explanation ?? [],
                ])
                ->all(),
        ];
    }

    private function sumLines($lines, string $lineType): string
    {
        return $this->moneyString($lines
            ->where('line_type', $lineType)
            ->sum(fn (PayrollResultLine $line): int => $this->moneyUnits($line->amount)));
    }

    /**
     * @param  list<array<string, mixed>>  $participants
     */
    private function sumPayload(array $participants, string $key): string
    {
        return $this->moneyString(collect($participants)->sum(fn (array $participant): int => $this->moneyUnits($participant[$key] ?? '0')));
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
