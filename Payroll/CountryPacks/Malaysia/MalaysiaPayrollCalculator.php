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
        $resultLines = [];
        $warnings = [];
        $blockingErrors = [];
        $categorySnapshot = $this->categorySnapshot($context);

        foreach ($this->contributionDefinitions() as $definition) {
            $calculation = $this->calculateContributionPair($context, $definition, $payDate, $metadata, $categorySnapshot);

            $resultLines = array_merge($resultLines, $calculation['resultLines']);
            $warnings = array_merge($warnings, $calculation['warnings']);
            $blockingErrors = array_merge($blockingErrors, $calculation['blockingErrors']);
            $metadata[$definition['metadata_key']] = $calculation['metadata'];
        }

        $hrdLevy = $this->calculateHrdLevy($context, $payDate, $metadata, $categorySnapshot);
        $resultLines = array_merge($resultLines, $hrdLevy['resultLines']);
        $warnings = array_merge($warnings, $hrdLevy['warnings']);
        $blockingErrors = array_merge($blockingErrors, $hrdLevy['blockingErrors']);
        $metadata['hrd_levy'] = $hrdLevy['metadata'];

        return new PayrollCalculationResult(
            resultLines: $resultLines,
            warnings: $warnings,
            blockingErrors: $blockingErrors,
            metadata: $metadata + [
                'status' => $resultLines === [] ? 'no_statutory_contributions' : 'statutory_contributions_calculated',
                'employee_category' => $categorySnapshot,
            ],
        );
    }

    /**
     * @param  array<string, mixed>  $definition
     * @param  array<string, mixed>  $baseMetadata
     * @param  array<string, mixed>  $categorySnapshot
     * @return array{resultLines: list<PayrollProposedResultLine>, warnings: list<array<string, mixed>>, blockingErrors: list<array<string, mixed>>, metadata: array<string, mixed>}
     */
    private function calculateContributionPair(
        PayrollCalculationContext $context,
        array $definition,
        string $payDate,
        array $baseMetadata,
        array $categorySnapshot,
    ): array {
        $wageUnits = $this->statutoryWageUnits($context, $definition['wage_base_keys']);

        if ($wageUnits <= 0) {
            return [
                'resultLines' => [],
                'warnings' => [],
                'blockingErrors' => [],
                'metadata' => [
                    'status' => 'no_wage_base',
                    'wage_base' => $this->moneyString($wageUnits),
                    'wage_base_keys' => $definition['wage_base_keys'],
                    'employee_category' => $categorySnapshot,
                ],
            ];
        }

        $ruleSet = $this->ruleSets->resolve(
            MalaysiaPayrollCountryPack::COUNTRY_ISO,
            $definition['rule_key'],
            $payDate,
        );

        if ($ruleSet === null) {
            return [
                'resultLines' => [],
                'warnings' => [],
                'blockingErrors' => [[
                    'code' => $definition['missing_warning_code'],
                    'message' => $definition['missing_warning_message'],
                ]],
                'metadata' => [
                    'status' => 'missing_rule_set',
                    'wage_base' => $this->moneyString($wageUnits),
                    'wage_base_keys' => $definition['wage_base_keys'],
                    'employee_category' => $categorySnapshot,
                ],
            ];
        }

        $row = $this->matchingRow($ruleSet->rows, $wageUnits, $categorySnapshot, $definition['metadata_key']);

        if ($row === null) {
            return [
                'resultLines' => [],
                'warnings' => [],
                'blockingErrors' => [],
                'metadata' => [
                    'status' => 'no_matching_rule_row',
                    'wage_base' => $this->moneyString($wageUnits),
                    'wage_base_keys' => $definition['wage_base_keys'],
                    'employee_category' => $categorySnapshot,
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
            'wage_base_keys' => $definition['wage_base_keys'],
            'employee_category' => $categorySnapshot,
            'employee_rate' => $row->employee_rate,
            'employer_rate' => $row->employer_rate,
            'employee_amount' => $row->employee_amount,
            'employer_amount' => $row->employer_amount,
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
            'blockingErrors' => [],
            'metadata' => [
                'status' => 'calculated',
                'wage_base' => $this->moneyString($wageUnits),
                'wage_base_keys' => $definition['wage_base_keys'],
                'rule_set_id' => $ruleSet->id,
                'rule_row_id' => $row->id,
                'employee_category' => $categorySnapshot,
                'employee_amount' => $this->moneyString($employeeContribution),
                'employer_amount' => $this->moneyString($employerContribution),
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $baseMetadata
     * @param  array<string, mixed>  $categorySnapshot
     * @return array{resultLines: list<PayrollProposedResultLine>, warnings: list<array<string, mixed>>, blockingErrors: list<array<string, mixed>>, metadata: array<string, mixed>}
     */
    private function calculateHrdLevy(PayrollCalculationContext $context, string $payDate, array $baseMetadata, array $categorySnapshot): array
    {
        if (($context->employerProfile?->profile_data['hrd_levy_applicable'] ?? false) !== true) {
            return [
                'resultLines' => [],
                'warnings' => [],
                'blockingErrors' => [],
                'metadata' => ['status' => 'not_applicable'],
            ];
        }

        $wageBaseKeys = ['hrd_levy_wage_base', 'statutory_wage_base'];
        $wageUnits = $this->statutoryWageUnits($context, $wageBaseKeys);

        if ($wageUnits <= 0) {
            return [
                'resultLines' => [],
                'warnings' => [],
                'blockingErrors' => [],
                'metadata' => [
                    'status' => 'no_wage_base',
                    'wage_base' => $this->moneyString($wageUnits),
                    'wage_base_keys' => $wageBaseKeys,
                    'employee_category' => $categorySnapshot,
                ],
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
                'warnings' => [],
                'blockingErrors' => [[
                    'code' => 'my_hrd_levy_rule_set_missing',
                    'message' => 'Malaysia HRD levy schedule is not configured for this pay date.',
                ]],
                'metadata' => [
                    'status' => 'missing_rule_set',
                    'wage_base' => $this->moneyString($wageUnits),
                    'wage_base_keys' => $wageBaseKeys,
                    'employee_category' => $categorySnapshot,
                ],
            ];
        }

        $row = $this->matchingRow($ruleSet->rows, $wageUnits, $categorySnapshot, 'hrd_levy');

        if ($row === null) {
            return [
                'resultLines' => [],
                'warnings' => [],
                'blockingErrors' => [],
                'metadata' => [
                    'status' => 'no_matching_rule_row',
                    'wage_base' => $this->moneyString($wageUnits),
                    'wage_base_keys' => $wageBaseKeys,
                    'employee_category' => $categorySnapshot,
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
            'wage_base_keys' => $wageBaseKeys,
            'employee_category' => $categorySnapshot,
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
            'blockingErrors' => [],
            'metadata' => [
                'status' => 'calculated',
                'wage_base' => $this->moneyString($wageUnits),
                'wage_base_keys' => $wageBaseKeys,
                'rule_set_id' => $ruleSet->id,
                'rule_row_id' => $row->id,
                'employee_category' => $categorySnapshot,
                'levy_amount' => $this->moneyString($levyAmount),
            ],
        ];
    }

    /**
     * @param  list<string>  $classificationKeys
     */
    private function statutoryWageUnits(PayrollCalculationContext $context, array $classificationKeys): int
    {
        return $context->inputs->sum(function ($input) use ($context, $classificationKeys): int {
            $classification = $this->firstClassificationValue(
                $context->classifications[(string) $input->id] ?? [],
                $classificationKeys,
            );

            if (! in_array($classification, ['ordinary_wage', 'additional_wage'], true)) {
                return 0;
            }

            return $this->moneyUnits($input->amount);
        });
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function contributionDefinitions(): array
    {
        return [
            [
                'rule_key' => 'epf_contribution_schedule',
                'metadata_key' => 'epf',
                'wage_base_keys' => ['epf_wage_base', 'statutory_wage_base'],
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
                'wage_base_keys' => ['socso_wage_base', 'statutory_wage_base'],
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
                'wage_base_keys' => ['eis_wage_base', 'statutory_wage_base'],
                'employee_code' => 'my_eis_employee',
                'employee_label' => 'EIS Employee Contribution',
                'employer_code' => 'my_eis_employer',
                'employer_label' => 'EIS Employer Contribution',
                'missing_warning_code' => 'my_eis_rule_set_missing',
                'missing_warning_message' => 'Malaysia EIS contribution schedule is not configured for this pay date.',
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $categorySnapshot
     */
    private function matchingRow($rows, int $wageUnits, array $categorySnapshot, string $component): ?PayrollStatutoryRuleRow
    {
        return $rows->first(function (PayrollStatutoryRuleRow $row) use ($wageUnits, $categorySnapshot, $component): bool {
            $min = $this->moneyUnits($row->min_wage);
            $max = $row->max_wage === null ? null : $this->moneyUnits($row->max_wage);

            return $wageUnits >= $min
                && ($max === null || $wageUnits <= $max)
                && $this->rowMatchesCategory($row, $categorySnapshot, $component);
        });
    }

    /**
     * @param  array<string, mixed>  $categorySnapshot
     */
    private function rowMatchesCategory(PayrollStatutoryRuleRow $row, array $categorySnapshot, string $component): bool
    {
        $rowData = $row->row_data ?? [];

        if (($rowData['component'] ?? $component) !== $component) {
            return false;
        }

        foreach (['employee_category', 'citizenship_status', 'age_category'] as $key) {
            if (array_key_exists($key, $rowData) && $rowData[$key] !== ($categorySnapshot[$key] ?? null)) {
                return false;
            }
        }

        $componentCategoryKey = $component.'_category';

        return ! array_key_exists($componentCategoryKey, $rowData)
            || $rowData[$componentCategoryKey] === ($categorySnapshot[$componentCategoryKey] ?? null);
    }

    /**
     * @param  array<string, array<string, mixed>>  $classifications
     * @param  list<string>  $classificationKeys
     */
    private function firstClassificationValue(array $classifications, array $classificationKeys): ?string
    {
        foreach ($classificationKeys as $key) {
            if (isset($classifications[$key]['value'])) {
                return (string) $classifications[$key]['value'];
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private function categorySnapshot(PayrollCalculationContext $context): array
    {
        $profile = $context->employeeProfile?->profile_data ?? [];

        return [
            'employee_category' => $profile['employee_category'] ?? $profile['citizenship_status'] ?? 'unspecified',
            'citizenship_status' => $profile['citizenship_status'] ?? 'unspecified',
            'age_category' => $profile['age_category'] ?? 'unspecified',
            'epf_category' => $profile['epf_category'] ?? $profile['citizenship_status'] ?? 'unspecified',
            'socso_category' => $profile['socso_category'] ?? $profile['citizenship_status'] ?? 'unspecified',
            'eis_category' => $profile['eis_category'] ?? $profile['citizenship_status'] ?? 'unspecified',
        ];
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
