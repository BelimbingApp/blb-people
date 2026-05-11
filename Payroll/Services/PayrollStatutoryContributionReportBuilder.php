<?php

namespace App\Modules\People\Payroll\Services;

use App\Modules\People\Payroll\Models\PayrollResultLine;
use App\Modules\People\Payroll\Models\PayrollRun;
use App\Modules\People\Payroll\Models\PayrollRunParticipant;

class PayrollStatutoryContributionReportBuilder
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
            'totals_by_code' => $this->totalsByCode($participants),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function participantPayload(PayrollRunParticipant $participant): array
    {
        $lines = $participant->resultLines
            ->filter(fn (PayrollResultLine $line): bool => in_array($line->line_type, [
                PayrollResultLine::TYPE_EMPLOYEE_CONTRIBUTION,
                PayrollResultLine::TYPE_EMPLOYER_CONTRIBUTION,
                PayrollResultLine::TYPE_EMPLOYER_LEVY,
                PayrollResultLine::TYPE_TAX,
            ], true))
            ->sortBy('id')
            ->values()
            ->map(fn (PayrollResultLine $line): array => [
                'type' => $line->line_type,
                'code' => $line->code,
                'label' => $line->label,
                'amount' => $line->amount,
                'source_rule' => $line->source_rule,
                'source_version' => $line->source_version,
                'explanation' => $line->explanation ?? [],
            ])
            ->all();

        return [
            'employee' => [
                'id' => $participant->employee->id,
                'number' => $participant->employee->employee_number,
                'name' => $participant->employee->displayName(),
            ],
            'lines' => $lines,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $participants
     * @return list<array<string, string>>
     */
    private function totalsByCode(array $participants): array
    {
        return collect($participants)
            ->flatMap(fn (array $participant): array => $participant['lines'])
            ->groupBy('code')
            ->map(fn ($lines, string $code): array => [
                'code' => $code,
                'label' => $lines->first()['label'],
                'type' => $lines->first()['type'],
                'amount' => $this->moneyString($lines->sum(fn (array $line): int => $this->moneyUnits($line['amount']))),
            ])
            ->values()
            ->all();
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
