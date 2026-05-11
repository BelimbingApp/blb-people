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
        $payDate = $context->payDate() ?? now()->toDateString();
        $wageUnits = $this->statutoryWageUnits($context);
        $resultLines = [];
        $warnings = [];

        foreach ($this->contributionDefinitions() as $definition) {
            $calculation = $this->calculateContributionPair($context, $definition, $wageUnits, $payDate, $metadata);

            $resultLines = array_merge($resultLines, $calculation['resultLines']);
            $warnings = array_merge($warnings, $calculation['warnings']);
            $metadata[$definition['metadata_key']] = $calculation['metadata'];
        }

        $hrdLevy = $this->calculateHrdLevy($context, $wageUnits, $payDate, $metadata);
        $resultLines = array_merge($resultLines, $hrdLevy['resultLines']);
        $warnings = array_merge($warnings, $hrdLevy['warnings']);
        $metadata['hrd_levy'] = $hrdLevy['metadata'];

        return new PayrollCalculationResult(
            resultLines: $resultLines,
            warnings: $warnings,
            metadata: $metadata + [
                'status' => $resultLines === [] ? 'no_statutory_contributions' : 'statutory_contributions_calculated',
                'statutory_wage_base' => $this->moneyString($wageUnits),
            ],
        );
    }

    /**
     * @param  array<string, string>  $definition
     * @param  array<string, mixed>  $baseMetadata
     * @return array{resultLines: list<PayrollProposedResultLine>, warnings: list<array<string, mixed>>, metadata: array<string, mixed>}
     */
    private function calculateContributionPair(
        PayrollCalculationContext $context,
        array $definition,
        int $wageUnits,
        string $payDate,
        array $baseMetadata,
    ): array {
        $ruleSet = $this->ruleSets->resolve(
            MalaysiaPayrollCountryPack::COUNTRY_ISO,
            $definition['rule_key'],
            $payDate,
        );

        if ($ruleSet === null) {
            return [
                'resultLines' => [],
                'warnings' => [[
                    'code' => $definition['missing_warning_code'],
                    'message' => $definition['missing_warning_message'],
                ]],
                'metadata' => ['status' => 'missing_rule_set'],
            ];
        }

        $row = $this->matchingRow($ruleSet->rows, $wageUnits);

        if ($wageUnits <= 0 || $row === null) {
            return [
                'resultLines' => [],
                'warnings' => [],
                'metadata' => [
                    'status' => 'no_wage_base',
                    'wage_base' => $this->moneyString($wageUnits),
                ],
            ];
        }

        $employeeContribution = $this->contributionAmount($wageUnits, $row->employee_rate, $row->employee_amount);
        $employerContribution = $this->contributionAmount($wageUnits, $row->employer_rate, $row->employer_amount);
        $explanation = [
            ...$baseMetadata,
            'rule_key' => $ruleSet->rule_key,
            'rule_set_id' => $ruleSet->id,
            'rule_row_id' => $row->id,
            'rule_row_key' => $row->row_key,
            'source_version' => $ruleSet->source_version,
            'wage_base' => $this->moneyString($wageUnits),
            'employee_rate' => $row->employee_rate,
            'employer_rate' => $row->employer_rate,
            'rounding_policy' => $ruleSet->rounding_policy,
            'employer_profile_id' => $context->employerProfile?->id,
            'employee_profile_id' => $context->employeeProfile?->id,
        ];

        return [
            'resultLines' => [
                new PayrollProposedResultLine(
                    lineType: PayrollResultLine::TYPE_EMPLOYEE_CONTRIBUTION,
                    code: $definition['employee_code'],
                    label: $definition['employee_label'],
                    amount: $this->moneyString($employeeContribution),
                    currency: $context->run->currency,
                    sourceRule: $ruleSet->rule_key,
                    sourceVersion: $ruleSet->source_version,
                    explanation: $explanation + ['share' => 'employee'],
                ),
                new PayrollProposedResultLine(
                    lineType: PayrollResultLine::TYPE_EMPLOYER_CONTRIBUTION,
                    code: $definition['employer_code'],
                    label: $definition['employer_label'],
                    amount: $this->moneyString($employerContribution),
                    currency: $context->run->currency,
                    sourceRule: $ruleSet->rule_key,
                    sourceVersion: $ruleSet->source_version,
                    explanation: $explanation + ['share' => 'employer'],
                ),
            ],
            'warnings' => [],
            'metadata' => [
                'status' => 'calculated',
                'wage_base' => $this->moneyString($wageUnits),
                'rule_set_id' => $ruleSet->id,
                'rule_row_id' => $row->id,
                'employee_amount' => $this->moneyString($employeeContribution),
                'employer_amount' => $this->moneyString($employerContribution),
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $baseMetadata
     * @return array{resultLines: list<PayrollProposedResultLine>, warnings: list<array<string, mixed>>, metadata: array<string, mixed>}
     */
    private function calculateHrdLevy(PayrollCalculationContext $context, int $wageUnits, string $payDate, array $baseMetadata): array
    {
        if (($context->employerProfile?->profile_data['hrd_levy_applicable'] ?? false) !== true) {
            return [
                'resultLines' => [],
                'warnings' => [],
                'metadata' => ['status' => 'not_applicable'],
            ];
        }

        $ruleSet = $this->ruleSets->resolve(
            MalaysiaPayrollCountryPack::COUNTRY_ISO,
            'hrd_levy_schedule',
            $payDate,
        );

        if ($ruleSet === null) {
            return [
                'resultLines' => [],
                'warnings' => [[
                    'code' => 'my_hrd_levy_rule_set_missing',
                    'message' => 'Malaysia HRD levy schedule is not configured for this pay date.',
                ]],
                'metadata' => ['status' => 'missing_rule_set'],
            ];
        }

        $row = $this->matchingRow($ruleSet->rows, $wageUnits);

        if ($wageUnits <= 0 || $row === null) {
            return [
                'resultLines' => [],
                'warnings' => [],
                'metadata' => [
                    'status' => 'no_wage_base',
                    'wage_base' => $this->moneyString($wageUnits),
                ],
            ];
        }

        $levyAmount = $this->contributionAmount($wageUnits, $row->levy_rate, null);
        $explanation = [
            ...$baseMetadata,
            'rule_key' => $ruleSet->rule_key,
            'rule_set_id' => $ruleSet->id,
            'rule_row_id' => $row->id,
            'rule_row_key' => $row->row_key,
            'source_version' => $ruleSet->source_version,
            'wage_base' => $this->moneyString($wageUnits),
            'levy_rate' => $row->levy_rate,
            'rounding_policy' => $ruleSet->rounding_policy,
            'employer_profile_id' => $context->employerProfile?->id,
            'employee_profile_id' => $context->employeeProfile?->id,
        ];

        return [
            'resultLines' => [
                new PayrollProposedResultLine(
                    lineType: PayrollResultLine::TYPE_EMPLOYER_LEVY,
                    code: 'my_hrd_levy',
                    label: 'HRD Levy',
                    amount: $this->moneyString($levyAmount),
                    currency: $context->run->currency,
                    sourceRule: $ruleSet->rule_key,
                    sourceVersion: $ruleSet->source_version,
                    explanation: $explanation + ['share' => 'employer'],
                ),
            ],
            'warnings' => [],
            'metadata' => [
                'status' => 'calculated',
                'wage_base' => $this->moneyString($wageUnits),
                'rule_set_id' => $ruleSet->id,
                'rule_row_id' => $row->id,
                'levy_amount' => $this->moneyString($levyAmount),
            ],
        ];
    }

    private function statutoryWageUnits(PayrollCalculationContext $context): int
    {
        return $context->inputs->sum(function ($input) use ($context): int {
            $classification = $context->classifications[(string) $input->id]['statutory_wage_base']['value'] ?? null;

            if (! in_array($classification, ['ordinary_wage', 'additional_wage'], true)) {
                return 0;
            }

            return $this->moneyUnits($input->amount);
        });
    }

    /**
     * @return list<array<string, string>>
     */
    private function contributionDefinitions(): array
    {
        return [
            [
                'rule_key' => 'epf_contribution_schedule',
                'metadata_key' => 'epf',
                'employee_code' => 'my_epf_employee',
                'employee_label' => 'EPF Employee Contribution',
                'employer_code' => 'my_epf_employer',
                'employer_label' => 'EPF Employer Contribution',
                'missing_warning_code' => 'my_epf_rule_set_missing',
                'missing_warning_message' => 'Malaysia EPF contribution schedule is not configured for this pay date.',
            ],
            [
                'rule_key' => 'socso_contribution_schedule',
                'metadata_key' => 'socso',
                'employee_code' => 'my_socso_employee',
                'employee_label' => 'SOCSO Employee Contribution',
                'employer_code' => 'my_socso_employer',
                'employer_label' => 'SOCSO Employer Contribution',
                'missing_warning_code' => 'my_socso_rule_set_missing',
                'missing_warning_message' => 'Malaysia SOCSO contribution schedule is not configured for this pay date.',
            ],
            [
                'rule_key' => 'eis_contribution_schedule',
                'metadata_key' => 'eis',
                'employee_code' => 'my_eis_employee',
                'employee_label' => 'EIS Employee Contribution',
                'employer_code' => 'my_eis_employer',
                'employer_label' => 'EIS Employer Contribution',
                'missing_warning_code' => 'my_eis_rule_set_missing',
                'missing_warning_message' => 'Malaysia EIS contribution schedule is not configured for this pay date.',
            ],
        ];
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
