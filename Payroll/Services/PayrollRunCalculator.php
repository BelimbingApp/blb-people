<?php

namespace App\Modules\People\Payroll\Services;

use App\Base\Foundation\Exceptions\BlbDataContractException;
use App\Modules\People\Payroll\Data\PayrollCalculationContext;
use App\Modules\People\Payroll\Data\PayrollCalculationResult;
use App\Modules\People\Payroll\Data\PayrollProposedResultLine;
use App\Modules\People\Payroll\Models\PayrollInput;
use App\Modules\People\Payroll\Models\PayrollPayItem;
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
            $run->loadMissing(['calendar', 'period']);
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
                    'country_pack' => $this->countryPackAuditPayload($run),
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

        $countryResult = $this->calculateCountryPack($run, $participant);

        foreach ($countryResult->resultLines as $line) {
            $this->writeCountryPackLine($run, $participant, $line);

            if (in_array($line->lineType, [PayrollResultLine::TYPE_EMPLOYEE_CONTRIBUTION, PayrollResultLine::TYPE_TAX], true)) {
                $totalDeductions += $this->moneyUnits($line->amount);
            }
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
                'country_pack' => $countryResult->metadata,
                'country_pack_warnings' => $countryResult->warnings,
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

    private function calculateCountryPack(PayrollRun $run, PayrollRunParticipant $participant): PayrollCalculationResult
    {
        $countryIso = $run->calendar?->country_iso;

        if ($countryIso === null || ! app(PayrollCountryPackRegistry::class)->hasCountry($countryIso)) {
            return new PayrollCalculationResult(metadata: [
                'status' => 'no_country_pack',
                'country_iso' => $countryIso,
            ]);
        }

        $payDate = $run->period?->pay_date?->toDateString() ?? $run->period?->ends_on?->toDateString() ?? now()->toDateString();
        $profileResolver = app(StatutoryProfileResolver::class);
        $pack = app(PayrollCountryPackRegistry::class)->forCountry($countryIso);
        $result = $pack->calculator()->calculate(new PayrollCalculationContext(
            run: $run,
            participant: $participant,
            inputs: $participant->inputs,
            employerProfile: $profileResolver->employerProfile($run->company_id, $countryIso, $payDate),
            employeeProfile: $profileResolver->employeeProfile($participant->employee_id, $countryIso, $payDate),
            classifications: $this->inputClassifications($participant, $countryIso, $payDate),
            metadata: [
                'country_iso' => strtoupper($countryIso),
                'pay_date' => $payDate,
                'pack_identifier' => $pack->manifest()->packIdentifier,
                'pack_version' => $pack->manifest()->packVersion,
            ],
        ));

        if ($result->hasBlockingErrors()) {
            throw new BlbDataContractException(
                'Payroll country pack returned blocking calculation errors.',
                context: [
                    'payroll_run_id' => $run->id,
                    'payroll_run_participant_id' => $participant->id,
                    'country_iso' => strtoupper($countryIso),
                    'errors' => $result->blockingErrors,
                ],
            );
        }

        return $result;
    }

    /**
     * @return array<string, array<string, array<string, mixed>>>
     */
    private function inputClassifications(PayrollRunParticipant $participant, string $countryIso, string $payDate): array
    {
        $payItems = PayrollPayItem::query()
            ->whereIn('code', $participant->inputs->pluck('pay_item_code')->unique()->all())
            ->where(function ($query) use ($participant): void {
                $query->where('company_id', $participant->company_id)
                    ->orWhereNull('company_id');
            })
            ->orderByRaw('company_id is null')
            ->get()
            ->unique('code')
            ->keyBy('code');

        return $participant->inputs
            ->mapWithKeys(function (PayrollInput $input) use ($payItems, $countryIso, $payDate): array {
                $payItem = $payItems->get($input->pay_item_code);

                return [
                    (string) $input->id => $payItem instanceof PayrollPayItem
                        ? app(PayItemClassifier::class)->classificationsFor($payItem, $countryIso, $payDate)
                        : [],
                ];
            })
            ->all();
    }

    private function writeCountryPackLine(
        PayrollRun $run,
        PayrollRunParticipant $participant,
        PayrollProposedResultLine $line,
    ): void {
        PayrollResultLine::query()->create([
            'payroll_run_id' => $run->id,
            'payroll_run_participant_id' => $participant->id,
            'employee_id' => $participant->employee_id,
            'payroll_input_id' => $line->payrollInputId,
            'line_type' => $line->lineType,
            'code' => $line->code,
            'label' => $line->label,
            'amount' => $line->amount,
            'currency' => $line->currency,
            'source_rule' => $line->sourceRule,
            'source_version' => $line->sourceVersion,
            'explanation' => $line->explanation,
            'metadata' => $line->metadata,
        ]);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function countryPackAuditPayload(PayrollRun $run): ?array
    {
        $countryIso = $run->calendar?->country_iso;

        if ($countryIso === null || ! app(PayrollCountryPackRegistry::class)->hasCountry($countryIso)) {
            return null;
        }

        $manifest = app(PayrollCountryPackRegistry::class)->forCountry($countryIso)->manifest();

        return [
            'country_iso' => $manifest->normalizedCountryIso(),
            'pack_identifier' => $manifest->packIdentifier,
            'pack_version' => $manifest->packVersion,
            'core_contract' => PayrollCountryPackRegistry::CORE_CONTRACT_VERSION,
        ];
    }
}
