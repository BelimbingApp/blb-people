<?php

namespace App\Modules\People\Payroll\Services;

use App\Modules\People\Payroll\Models\PayrollRun;
use App\Modules\People\Payroll\Models\PayrollRunParticipant;

class PayrollBankPaymentExportBuilder
{
    /**
     * @return array<string, mixed>
     */
    public function build(PayrollRun $run): array
    {
        $run->loadMissing(['period', 'participants.employee']);
        $rows = $run->participants
            ->sortBy(fn (PayrollRunParticipant $participant): string => (string) $participant->employee?->employee_number)
            ->map(fn (PayrollRunParticipant $participant): array => $this->rowPayload($run, $participant))
            ->values()
            ->all();

        return [
            'filename' => 'payroll-bank-payment-placeholder-'.$run->code.'.csv',
            'format' => 'csv',
            'status' => 'placeholder',
            'description' => 'Internal review CSV only. Not a bank-submittable file until the SBG bank format is confirmed.',
            'headers' => $this->headers(),
            'rows' => $rows,
            'content' => $this->csv($rows),
            'totals' => [
                'rows' => count($rows),
                'amount' => $this->moneyString(collect($rows)->sum(fn (array $row): int => $this->moneyUnits($row['amount']))),
                'missing_bank_details' => collect($rows)->where('status', 'missing_bank_details')->count(),
            ],
        ];
    }

    /**
     * @return list<string>
     */
    private function headers(): array
    {
        return [
            'export_status',
            'payroll_run_code',
            'period_code',
            'employee_number',
            'employee_name',
            'bank_name',
            'bank_account_number',
            'amount',
            'currency',
            'status',
            'notes',
        ];
    }

    /**
     * @return array<string, string>
     */
    private function rowPayload(PayrollRun $run, PayrollRunParticipant $participant): array
    {
        $bank = $participant->employee?->metadata['payroll_bank'] ?? [];
        $bankName = is_array($bank) ? (string) ($bank['bank_name'] ?? '') : '';
        $bankAccountNumber = is_array($bank) ? (string) ($bank['bank_account_number'] ?? '') : '';
        $hasBankDetails = $bankName !== '' && $bankAccountNumber !== '';

        return [
            'export_status' => 'placeholder_not_bank_submittable',
            'payroll_run_code' => $run->code,
            'period_code' => (string) ($run->period?->code ?? ''),
            'employee_number' => (string) $participant->employee?->employee_number,
            'employee_name' => (string) $participant->employee?->displayName(),
            'bank_name' => $bankName,
            'bank_account_number' => $bankAccountNumber,
            'amount' => (string) $participant->net_pay,
            'currency' => $participant->currency,
            'status' => $hasBankDetails ? 'ready_for_mapping' : 'missing_bank_details',
            'notes' => $hasBankDetails
                ? 'Map this row after SBG bank format is confirmed.'
                : 'Employee payroll_bank metadata is not configured.',
        ];
    }

    /**
     * @param  list<array<string, string>>  $rows
     */
    private function csv(array $rows): string
    {
        $handle = fopen('php://temp', 'r+');
        fputcsv($handle, $this->headers());

        foreach ($rows as $row) {
            fputcsv($handle, array_map(fn (string $header): string => $row[$header] ?? '', $this->headers()));
        }

        rewind($handle);
        $csv = stream_get_contents($handle);
        fclose($handle);

        return $csv === false ? '' : $csv;
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
