<?php

namespace App\Modules\People\Payroll\Services;

use App\Modules\People\Payroll\Models\PayrollResultLine;
use App\Modules\People\Payroll\Models\PayrollRun;
use App\Modules\People\Payroll\Models\PayrollRunParticipant;

class PayrollSummaryReportBuilder
{
    /**
     * @return array<string, mixed>
     */
    public function build(PayrollRun $run): array
    {
        $run->loadMissing(['calendar', 'period', 'participants.employee', 'participants.resultLines']);

        return [
            'run' => $this->runPayload($run),
            'participants' => $run->participants
                ->map(fn (PayrollRunParticipant $participant): array => $this->participantPayload($participant))
                ->values()
                ->all(),
            'totals' => $this->totalsPayload($run),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function runPayload(PayrollRun $run): array
    {
        return [
            'id' => $run->id,
            'code' => $run->code,
            'name' => $run->name,
            'status' => $run->status,
            'country_iso' => $run->calendar?->country_iso,
            'currency' => $run->currency,
            'period' => $run->period?->code,
            'period_name' => $run->period?->name,
            'starts_on' => $run->period?->starts_on?->toDateString(),
            'ends_on' => $run->period?->ends_on?->toDateString(),
            'pay_date' => $run->period?->pay_date?->toDateString(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function participantPayload(PayrollRunParticipant $participant): array
    {
        return [
            'employee' => [
                'id' => $participant->employee->id,
                'number' => $participant->employee->employee_number,
                'name' => $participant->employee->displayName(),
            ],
            'gross_pay' => $participant->gross_pay,
            'employee_deductions' => $this->sumLines($participant, PayrollResultLine::TYPE_EMPLOYEE_DEDUCTION),
            'employee_contributions' => $this->sumLines($participant, PayrollResultLine::TYPE_EMPLOYEE_CONTRIBUTION),
            'taxes' => $this->sumLines($participant, PayrollResultLine::TYPE_TAX),
            'reimbursements' => $participant->total_reimbursements,
            'net_pay' => $participant->net_pay,
            'employer_contributions' => $this->sumLines($participant, PayrollResultLine::TYPE_EMPLOYER_CONTRIBUTION),
            'employer_levies' => $this->sumLines($participant, PayrollResultLine::TYPE_EMPLOYER_LEVY),
        ];
    }

    /**
     * @return array<string, string>
     */
    private function totalsPayload(PayrollRun $run): array
    {
        return [
            'gross_pay' => $this->sumParticipants($run, 'gross_pay'),
            'employee_deductions' => $this->sumResultLines($run, PayrollResultLine::TYPE_EMPLOYEE_DEDUCTION),
            'employee_contributions' => $this->sumResultLines($run, PayrollResultLine::TYPE_EMPLOYEE_CONTRIBUTION),
            'taxes' => $this->sumResultLines($run, PayrollResultLine::TYPE_TAX),
            'reimbursements' => $this->sumParticipants($run, 'total_reimbursements'),
            'net_pay' => $this->sumParticipants($run, 'net_pay'),
            'employer_contributions' => $this->sumResultLines($run, PayrollResultLine::TYPE_EMPLOYER_CONTRIBUTION),
            'employer_levies' => $this->sumResultLines($run, PayrollResultLine::TYPE_EMPLOYER_LEVY),
        ];
    }

    private function sumLines(PayrollRunParticipant $participant, string $lineType): string
    {
        return $this->moneyString($participant->resultLines
            ->where('line_type', $lineType)
            ->sum(fn (PayrollResultLine $line): int => $this->moneyUnits($line->amount)));
    }

    private function sumResultLines(PayrollRun $run, string $lineType): string
    {
        return $this->moneyString($run->participants
            ->flatMap(fn (PayrollRunParticipant $participant) => $participant->resultLines)
            ->where('line_type', $lineType)
            ->sum(fn (PayrollResultLine $line): int => $this->moneyUnits($line->amount)));
    }

    private function sumParticipants(PayrollRun $run, string $field): string
    {
        return $this->moneyString($run->participants->sum(fn (PayrollRunParticipant $participant): int => $this->moneyUnits($participant->{$field})));
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
