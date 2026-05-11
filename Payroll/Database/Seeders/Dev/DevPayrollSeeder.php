<?php

namespace App\Modules\People\Payroll\Database\Seeders\Dev;

use App\Base\Database\Seeders\DevSeeder;
use App\Modules\Core\Company\Models\Company;
use App\Modules\Core\Employee\Database\Seeders\Dev\DevEmployeeSeeder;
use App\Modules\Core\Employee\Models\Employee;
use App\Modules\People\Payroll\Models\PayrollCalendar;
use App\Modules\People\Payroll\Models\PayrollEmployeeStatutoryProfile;
use App\Modules\People\Payroll\Models\PayrollEmployerStatutoryProfile;
use App\Modules\People\Payroll\Models\PayrollInput;
use App\Modules\People\Payroll\Models\PayrollPayItem;
use App\Modules\People\Payroll\Models\PayrollPayItemClassification;
use App\Modules\People\Payroll\Models\PayrollPeriod;
use App\Modules\People\Payroll\Models\PayrollRun;
use App\Modules\People\Payroll\Models\PayrollRunParticipant;
use App\Modules\People\Payroll\Models\PayrollStatutoryRuleRow;
use App\Modules\People\Payroll\Models\PayrollStatutoryRuleSet;
use App\Modules\People\Payroll\Services\PayrollRunCalculator;

class DevPayrollSeeder extends DevSeeder
{
    protected array $dependencies = [
        DevEmployeeSeeder::class,
    ];

    protected function seed(): void
    {
        $company = $this->licenseeCompany();

        if ($company === null) {
            return;
        }

        $payItems = $this->seedPayItems($company);
        $this->seedStatutoryProfiles($company);
        $this->seedStatutoryRuleSets();

        $calendar = $this->seedCalendar($company);
        $january = $this->seedPeriod($calendar, '2026-01', 'January 2026', '2026-01-01', '2026-01-31');
        $february = $this->seedPeriod($calendar, '2026-02', 'February 2026', '2026-02-01', '2026-02-28');

        $employees = Employee::query()
            ->where('company_id', $company->id)
            ->where('status', 'active')
            ->where('employee_type', '!=', 'agent')
            ->orderBy('employee_number')
            ->limit(4)
            ->get();

        if ($employees->isEmpty()) {
            return;
        }

        $this->seedRun($company, $calendar, $january, 'MY-2026-01-MAIN', 'January 2026 Main Payroll', $employees, $payItems, close: true);
        $this->seedRun($company, $calendar, $february, 'MY-2026-02-MAIN', 'February 2026 Main Payroll', $employees, $payItems, close: false);
    }

    /**
     * @return array<string, PayrollPayItem>
     */
    private function seedPayItems(Company $company): array
    {
        $items = [];

        foreach ($this->payItemDefinitions() as $definition) {
            $item = PayrollPayItem::query()->updateOrCreate(
                [
                    'company_id' => $company->id,
                    'code' => $definition['code'],
                ],
                [
                    'name' => $definition['name'],
                    'input_type' => $definition['input_type'],
                    'status' => 'active',
                    'metadata' => $definition['metadata'] ?? [],
                ],
            );

            foreach ($definition['classifications'] as $classification) {
                PayrollPayItemClassification::query()->updateOrCreate(
                    [
                        'payroll_pay_item_id' => $item->id,
                        'country_iso' => $classification['country_iso'],
                        'classification_key' => $classification['classification_key'],
                        'effective_from' => $classification['effective_from'],
                    ],
                    [
                        'classification_value' => $classification['classification_value'],
                        'effective_to' => $classification['effective_to'] ?? null,
                        'source_pack' => $classification['source_pack'],
                        'source_version' => $classification['source_version'],
                        'metadata' => $classification['metadata'] ?? [],
                    ],
                );
            }

            $items[$definition['code']] = $item;
        }

        return $items;
    }

    private function seedStatutoryProfiles(Company $company): void
    {
        PayrollEmployerStatutoryProfile::query()->updateOrCreate(
            [
                'company_id' => $company->id,
                'country_iso' => 'MY',
                'effective_from' => '2026-01-01',
            ],
            [
                'source_pack' => 'belimbing/payroll-my',
                'source_version' => '2026.dev',
                'effective_to' => null,
                'profile_data' => [
                    'epf_employer_number' => 'KWSP-DEV-001',
                    'socso_employer_number' => 'PERKESO-DEV-001',
                    'lhdn_employer_number' => 'E-DEV-001',
                    'hrd_levy_applicable' => true,
                    'zakat_salary_deduction_supported' => true,
                ],
                'validation_messages' => [],
                'metadata' => ['scenario' => 'browser-demo'],
            ],
        );

        Employee::query()
            ->where('company_id', $company->id)
            ->where('status', 'active')
            ->orderBy('employee_number')
            ->limit(8)
            ->get()
            ->each(function (Employee $employee, int $index) use ($company): void {
                PayrollEmployeeStatutoryProfile::query()->updateOrCreate(
                    [
                        'company_id' => $company->id,
                        'employee_id' => $employee->id,
                        'country_iso' => 'MY',
                        'effective_from' => '2026-01-01',
                    ],
                    [
                        'source_pack' => 'belimbing/payroll-my',
                        'source_version' => '2026.dev',
                        'effective_to' => null,
                        'profile_data' => [
                            'citizenship_status' => $index === 3 ? 'permanent_resident' : 'citizen',
                            'tax_residency' => 'resident',
                            'epf_number' => 'KWSP-'.str_pad((string) ($employee->id + 1000), 6, '0', STR_PAD_LEFT),
                            'socso_number' => 'SOCSO-'.str_pad((string) ($employee->id + 2000), 6, '0', STR_PAD_LEFT),
                            'tax_number' => 'SG'.str_pad((string) ($employee->id + 3000), 8, '0', STR_PAD_LEFT),
                            'zakat_salary_deduction_authorized' => $index === 1,
                        ],
                        'validation_messages' => [],
                        'metadata' => ['scenario' => 'browser-demo'],
                    ],
                );
            });
    }

    private function seedStatutoryRuleSets(): void
    {
        $this->seedContributionRuleSet(
            ruleKey: 'epf_contribution_schedule',
            name: 'EPF contribution schedule — dev fixture',
            rows: [
                ['band-1', 10, '0.0000', '5000.0000', '0.11000000', '0.13000000'],
                ['band-2', 20, '5000.0100', null, '0.11000000', '0.12000000'],
            ],
        );
        $this->seedContributionRuleSet(
            ruleKey: 'socso_contribution_schedule',
            name: 'SOCSO contribution schedule — dev fixture',
            rows: [
                ['standard', 10, '0.0000', null, '0.00500000', '0.01750000'],
            ],
        );
        $this->seedContributionRuleSet(
            ruleKey: 'eis_contribution_schedule',
            name: 'EIS contribution schedule — dev fixture',
            rows: [
                ['standard', 10, '0.0000', null, '0.00200000', '0.00200000'],
            ],
        );
        $this->seedContributionRuleSet(
            ruleKey: 'hrd_levy_schedule',
            name: 'HRD levy schedule — dev fixture',
            rows: [
                ['standard', 10, '0.0000', null, null, null, '0.01000000'],
            ],
        );
    }

    /**
     * @param  list<array{0: string, 1: int, 2: string, 3: string|null, 4: string|null, 5: string|null, 6?: string|null}>  $rows
     */
    private function seedContributionRuleSet(string $ruleKey, string $name, array $rows): void
    {
        $ruleSet = PayrollStatutoryRuleSet::query()->updateOrCreate(
            [
                'country_iso' => 'MY',
                'rule_key' => $ruleKey,
                'source_pack' => 'belimbing/payroll-my',
                'source_version' => '2026.dev',
                'effective_from' => '2026-01-01',
            ],
            [
                'name' => $name,
                'effective_to' => null,
                'rounding_policy' => ['mode' => 'ceiling', 'precision' => '0.01'],
                'metadata' => ['scenario' => 'browser-demo', 'official' => false],
            ],
        );

        $ruleSet->rows()->delete();
        foreach ($rows as $row) {
            [$key, $order, $min, $max, $employeeRate, $employerRate] = $row;

            PayrollStatutoryRuleRow::query()->create([
                'payroll_statutory_rule_set_id' => $ruleSet->id,
                'sort_order' => $order,
                'row_key' => $key,
                'min_wage' => $min,
                'max_wage' => $max,
                'employee_rate' => $employeeRate,
                'employer_rate' => $employerRate,
                'levy_rate' => $row[6] ?? null,
                'row_data' => ['category' => 'standard-dev-fixture'],
            ]);
        }
    }

    private function seedCalendar(Company $company): PayrollCalendar
    {
        return PayrollCalendar::query()->updateOrCreate(
            [
                'company_id' => $company->id,
                'code' => 'MY-MONTHLY',
            ],
            [
                'name' => 'Malaysia Monthly Payroll',
                'country_iso' => 'MY',
                'currency' => 'MYR',
                'frequency' => 'monthly',
                'status' => 'active',
                'metadata' => ['scenario' => 'browser-demo'],
            ],
        );
    }

    private function seedPeriod(PayrollCalendar $calendar, string $code, string $name, string $startsOn, string $endsOn): PayrollPeriod
    {
        return PayrollPeriod::query()->updateOrCreate(
            [
                'payroll_calendar_id' => $calendar->id,
                'code' => $code,
            ],
            [
                'name' => $name,
                'starts_on' => $startsOn,
                'ends_on' => $endsOn,
                'pay_date' => $endsOn,
                'status' => 'open',
                'metadata' => ['scenario' => 'browser-demo'],
            ],
        );
    }

    private function seedRun(
        Company $company,
        PayrollCalendar $calendar,
        PayrollPeriod $period,
        string $code,
        string $name,
        $employees,
        array $payItems,
        bool $close,
    ): void {
        $run = PayrollRun::query()->firstOrCreate(
            [
                'company_id' => $company->id,
                'code' => $code,
            ],
            [
                'payroll_calendar_id' => $calendar->id,
                'payroll_period_id' => $period->id,
                'name' => $name,
                'status' => PayrollRun::STATUS_DRAFT,
                'currency' => 'MYR',
                'metadata' => ['scenario' => 'browser-demo'],
            ],
        );

        if ($run->isClosed()) {
            return;
        }

        $run->forceFill([
            'payroll_calendar_id' => $calendar->id,
            'payroll_period_id' => $period->id,
            'name' => $name,
            'status' => PayrollRun::STATUS_DRAFT,
            'currency' => 'MYR',
            'calculated_at' => null,
            'reviewed_at' => null,
            'approved_at' => null,
            'closed_at' => null,
            'voided_at' => null,
            'metadata' => ['scenario' => 'browser-demo'],
        ])->save();

        $run->resultLines()->delete();
        $run->inputs()->delete();
        $run->participants()->delete();
        $run->auditEvents()->delete();

        foreach ($employees->values() as $index => $employee) {
            $participant = PayrollRunParticipant::query()->create([
                'payroll_run_id' => $run->id,
                'company_id' => $company->id,
                'employee_id' => $employee->id,
                'status' => 'included',
                'currency' => 'MYR',
                'metadata' => ['scenario' => 'browser-demo'],
            ]);

            $this->seedInputsForParticipant($run, $participant, $employee, $index, $payItems);
        }

        app(PayrollRunCalculator::class)->calculate($run->refresh());

        if ($close) {
            $run->refresh()->markReviewed();
            $run->refresh()->approve();
            $run->refresh()->close();
        }
    }

    private function seedInputsForParticipant(PayrollRun $run, PayrollRunParticipant $participant, Employee $employee, int $index, array $payItems): void
    {
        $salary = 3200 + ($index * 450);

        foreach ([
            ['basic_salary', PayrollInput::TYPE_EARNING, $salary],
            ['fixed_allowance', PayrollInput::TYPE_EARNING, 250 + ($index * 25)],
            ['advance_recovery', PayrollInput::TYPE_DEDUCTION, $index === 0 ? 0 : 100],
            ['travel_claim', PayrollInput::TYPE_REIMBURSEMENT, 80 + ($index * 15)],
        ] as [$code, $type, $amount]) {
            if ($amount <= 0) {
                continue;
            }

            PayrollInput::query()->create([
                'payroll_run_id' => $run->id,
                'payroll_run_participant_id' => $participant->id,
                'employee_id' => $employee->id,
                'source_type' => 'dev-seeder',
                'source_id' => $payItems[$code]?->id,
                'pay_item_code' => $code,
                'label' => $payItems[$code]?->name ?? ucwords(str_replace('_', ' ', $code)),
                'input_type' => $type,
                'amount' => number_format($amount, 4, '.', ''),
                'currency' => 'MYR',
                'occurred_on' => $run->period->pay_date,
                'metadata' => ['scenario' => 'browser-demo'],
            ]);
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function payItemDefinitions(): array
    {
        return [
            $this->payItem('basic_salary', 'Basic Salary', PayrollInput::TYPE_EARNING, 'regular_earning', 'ordinary_wage'),
            $this->payItem('fixed_allowance', 'Fixed Allowance', PayrollInput::TYPE_EARNING, 'regular_earning', 'ordinary_wage'),
            $this->payItem('bonus', 'Bonus', PayrollInput::TYPE_EARNING, 'additional_earning', 'additional_wage'),
            $this->payItem('overtime', 'Overtime', PayrollInput::TYPE_EARNING, 'variable_earning', 'ordinary_wage'),
            $this->payItem('travel_claim', 'Travel Claim', PayrollInput::TYPE_REIMBURSEMENT, 'claim_reimbursement', 'excluded'),
            $this->payItem('advance_recovery', 'Advance Recovery', PayrollInput::TYPE_DEDUCTION, 'employee_deduction', 'excluded'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function payItem(string $code, string $name, string $inputType, string $family, string $wageBase): array
    {
        return [
            'code' => $code,
            'name' => $name,
            'input_type' => $inputType,
            'classifications' => [
                [
                    'country_iso' => null,
                    'classification_key' => 'payroll_input_family',
                    'classification_value' => $family,
                    'effective_from' => '2026-01-01',
                    'source_pack' => 'payroll-core',
                    'source_version' => 'v0',
                ],
                [
                    'country_iso' => 'MY',
                    'classification_key' => 'statutory_wage_base',
                    'classification_value' => $wageBase,
                    'effective_from' => '2026-01-01',
                    'source_pack' => 'belimbing/payroll-my',
                    'source_version' => '2026.dev',
                    'metadata' => ['scenario' => 'browser-demo'],
                ],
            ],
        ];
    }
}
