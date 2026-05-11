<?php
namespace App\Modules\People\Payroll\Services;

use App\Modules\People\Payroll\Models\PayrollInput;
use App\Modules\People\Payroll\Models\PayrollResultLine;
use App\Modules\People\Payroll\Models\PayrollRun;
use App\Modules\People\Payroll\Models\PayrollRunParticipant;
use Illuminate\Support\Facades\DB;

class PayrollRunCalculator
{
    public function calculate(PayrollRun $run): PayrollRun
    {
        $run->assertMutable();

        return DB::transaction(function () use ($run): PayrollRun {
            $run->resultLines()->delete();

            $run->participants()->with('inputs')->get()->each(function (PayrollRunParticipant $participant) use ($run): void {
                $this->calculateParticipant($run, $participant);
            });

            $run->markCalculated();

            $run->auditEvents()->create([
                'action' => 'calculated',
                'message' => 'Payroll run calculated by the country-neutral core calculator.',
                'payload' => [
                    'calculator' => self::class,
                ],
                'occurred_at' => now(),
            ]);

            return $run->refresh();
        });
    }

    private function calculateParticipant(PayrollRun $run, PayrollRunParticipant $participant): void
    {
        $grossPay = 0;
        $totalDeductions = 0;
        $totalReimbursements = 0;

        foreach ($participant->inputs as $input) {
            if ($input->input_type === PayrollInput::TYPE_DEDUCTION) {
                $totalDeductions += $this->moneyUnits($input->amount);
                $this->writeInputLine($run, $participant, $input, PayrollResultLine::TYPE_EMPLOYEE_DEDUCTION);

                continue;
            }

            if ($input->input_type === PayrollInput::TYPE_REIMBURSEMENT) {
                $totalReimbursements += $this->moneyUnits($input->amount);
                $this->writeInputLine($run, $participant, $input, PayrollResultLine::TYPE_REIMBURSEMENT);

                continue;
            }

            $grossPay += $this->moneyUnits($input->amount);
            $this->writeInputLine($run, $participant, $input, PayrollResultLine::TYPE_EARNING);
        }

        $netPay = $grossPay + $totalReimbursements - $totalDeductions;

        PayrollResultLine::query()->create([
            'payroll_run_id' => $run->id,
            'payroll_run_participant_id' => $participant->id,
            'employee_id' => $participant->employee_id,
            'line_type' => PayrollResultLine::TYPE_NET_PAY,
            'code' => 'net_pay',
            'label' => 'Net Pay',
            'amount' => $this->moneyString($netPay),
            'currency' => $run->currency,
            'source_rule' => 'payroll-core-neutral-net-pay',
            'source_version' => 'v0',
            'explanation' => [
                'gross_pay' => $this->moneyString($grossPay),
                'total_deductions' => $this->moneyString($totalDeductions),
                'total_reimbursements' => $this->moneyString($totalReimbursements),
            ],
        ]);

        $participant->forceFill([
            'gross_pay' => $this->moneyString($grossPay),
            'total_deductions' => $this->moneyString($totalDeductions),
            'total_reimbursements' => $this->moneyString($totalReimbursements),
            'net_pay' => $this->moneyString($netPay),
        ])->save();
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

    private function writeInputLine(
        PayrollRun $run,
        PayrollRunParticipant $participant,
        PayrollInput $input,
        string $lineType,
    ): void {
        PayrollResultLine::query()->create([
            'payroll_run_id' => $run->id,
            'payroll_run_participant_id' => $participant->id,
            'employee_id' => $participant->employee_id,
            'payroll_input_id' => $input->id,
            'line_type' => $lineType,
            'code' => $input->pay_item_code,
            'label' => $input->label,
            'amount' => $input->amount,
            'currency' => $input->currency,
            'source_rule' => 'payroll-core-input-copy',
            'source_version' => 'v0',
            'explanation' => [
                'input_type' => $input->input_type,
                'pay_item_code' => $input->pay_item_code,
            ],
        ]);
    }
}
