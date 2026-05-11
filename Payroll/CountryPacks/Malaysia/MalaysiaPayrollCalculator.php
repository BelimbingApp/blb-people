<?php

namespace App\Modules\People\Payroll\CountryPacks\Malaysia;

use App\Modules\People\Payroll\Contracts\CalculatesPayrollRun;
use App\Modules\People\Payroll\Data\PayrollCalculationContext;
use App\Modules\People\Payroll\Data\PayrollCalculationResult;
use App\Modules\People\Payroll\Data\PayrollProposedResultLine;
use App\Modules\People\Payroll\Models\PayrollResultLine;
use App\Modules\People\Payroll\Models\PayrollStatutoryRuleRow;
use App\Modules\People\Payroll\Services\StatutoryRuleSetResolver;

class MalaysiaPayrollCalculator implements CalculatesPayrollRun
{
    public function __construct(private readonly StatutoryRuleSetResolver $ruleSets) {}

    public function calculate(PayrollCalculationContext $context): PayrollCalculationResult
    {
        $metadata = [
            'country_iso' => MalaysiaPayrollCountryPack::COUNTRY_ISO,
            'pack_identifier' => MalaysiaPayrollCountryPack::PACK_IDENTIFIER,
            'pack_version' => MalaysiaPayrollCountryPack::PACK_VERSION,
        ];

        $epfRuleSet = $this->ruleSets->resolve(
            MalaysiaPayrollCountryPack::COUNTRY_ISO,
            'epf_contribution_schedule',
            $context->payDate() ?? now()->toDateString(),
        );

        if ($epfRuleSet === null) {
            return new PayrollCalculationResult(
                warnings: [[
                    'code' => 'my_epf_rule_set_missing',
                    'message' => 'Malaysia EPF contribution schedule is not configured for this pay date.',
                ]],
                metadata: $metadata + ['status' => 'missing_epf_rule_set'],
            );
        }

        $epfWageUnits = $this->epfWageUnits($context);
        $epfRow = $this->matchingRow($epfRuleSet->rows, $epfWageUnits);

        if ($epfWageUnits <= 0 || $epfRow === null) {
            return new PayrollCalculationResult(metadata: $metadata + [
                'status' => 'no_epf_wage_base',
                'epf_wage_base' => $this->moneyString($epfWageUnits),
            ]);
        }

        $employeeContribution = $this->contributionAmount($epfWageUnits, $epfRow->employee_rate, $epfRow->employee_amount);
        $employerContribution = $this->contributionAmount($epfWageUnits, $epfRow->employer_rate, $epfRow->employer_amount);
        $explanation = [
            'country_iso' => MalaysiaPayrollCountryPack::COUNTRY_ISO,
            'pack_identifier' => MalaysiaPayrollCountryPack::PACK_IDENTIFIER,
            'pack_version' => MalaysiaPayrollCountryPack::PACK_VERSION,
            'rule_key' => $epfRuleSet->rule_key,
            'rule_set_id' => $epfRuleSet->id,
            'rule_row_id' => $epfRow->id,
            'rule_row_key' => $epfRow->row_key,
            'source_version' => $epfRuleSet->source_version,
            'wage_base' => $this->moneyString($epfWageUnits),
            'employee_rate' => $epfRow->employee_rate,
            'employer_rate' => $epfRow->employer_rate,
            'rounding_policy' => $epfRuleSet->rounding_policy,
            'employer_profile_id' => $context->employerProfile?->id,
            'employee_profile_id' => $context->employeeProfile?->id,
        ];

        return new PayrollCalculationResult(
            resultLines: [
                new PayrollProposedResultLine(
                    lineType: PayrollResultLine::TYPE_EMPLOYEE_CONTRIBUTION,
                    code: 'my_epf_employee',
                    label: 'EPF Employee Contribution',
                    amount: $this->moneyString($employeeContribution),
                    currency: $context->run->currency,
                    sourceRule: $epfRuleSet->rule_key,
                    sourceVersion: $epfRuleSet->source_version,
                    explanation: $explanation + ['share' => 'employee'],
                ),
                new PayrollProposedResultLine(
                    lineType: PayrollResultLine::TYPE_EMPLOYER_CONTRIBUTION,
                    code: 'my_epf_employer',
                    label: 'EPF Employer Contribution',
                    amount: $this->moneyString($employerContribution),
                    currency: $context->run->currency,
                    sourceRule: $epfRuleSet->rule_key,
                    sourceVersion: $epfRuleSet->source_version,
                    explanation: $explanation + ['share' => 'employer'],
                ),
            ],
            metadata: $metadata + [
                'status' => 'epf_calculated',
                'epf_wage_base' => $this->moneyString($epfWageUnits),
            ],
        );
    }

    private function epfWageUnits(PayrollCalculationContext $context): int
    {
        return $context->inputs->sum(function ($input) use ($context): int {
            $classification = $context->classifications[(string) $input->id]['statutory_wage_base']['value'] ?? null;

            if (! in_array($classification, ['ordinary_wage', 'additional_wage'], true)) {
                return 0;
            }

            return $this->moneyUnits($input->amount);
        });
    }

    private function matchingRow($rows, int $wageUnits): ?PayrollStatutoryRuleRow
    {
        return $rows->first(function (PayrollStatutoryRuleRow $row) use ($wageUnits): bool {
            $min = $this->moneyUnits($row->min_wage);
            $max = $row->max_wage === null ? null : $this->moneyUnits($row->max_wage);

            return $wageUnits >= $min && ($max === null || $wageUnits <= $max);
        });
    }

    private function contributionAmount(int $wageUnits, string|int|float|null $rate, string|int|float|null $fixedAmount): int
    {
        if ($fixedAmount !== null) {
            return $this->moneyUnits($fixedAmount);
        }

        $rateUnits = $this->rateUnits($rate);
        $rawUnits = intdiv(($wageUnits * $rateUnits) + 99_999_999, 100_000_000);

        return intdiv($rawUnits + 99, 100) * 100;
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

    private function rateUnits(string|int|float|null $rate): int
    {
        $normalized = trim((string) ($rate ?? '0'));
        [$whole, $fraction] = array_pad(explode('.', $normalized, 2), 2, '');

        return ((int) $whole * 100_000_000) + (int) str_pad(substr($fraction, 0, 8), 8, '0');
    }

    private function moneyString(int $units): string
    {
        $sign = $units < 0 ? '-' : '';
        $absolute = abs($units);

        return sprintf('%s%d.%04d', $sign, intdiv($absolute, 10000), $absolute % 10000);
    }
}
