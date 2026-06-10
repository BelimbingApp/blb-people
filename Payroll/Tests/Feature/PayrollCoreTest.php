<?php

use App\Base\Foundation\Exceptions\BlbDataContractException;
use App\Base\Pdf\Events\PdfArtifactRendered;
use App\Base\Pdf\ValueObjects\PdfArtifact;
use App\Modules\Core\Company\Models\Company;
use App\Modules\Core\Employee\Models\Employee;
use App\Modules\People\Payroll\Contracts\CalculatesPayrollRun;
use App\Modules\People\Payroll\Contracts\ClassifiesPayrollPayItems;
use App\Modules\People\Payroll\Contracts\PayrollCountryPack;
use App\Modules\People\Payroll\Contracts\ProvidesPayrollExports;
use App\Modules\People\Payroll\Contracts\ProvidesPayrollProfileSchemas;
use App\Modules\People\Payroll\CountryPacks\Malaysia\MalaysiaPayrollCountryPack;
use App\Modules\People\Payroll\Data\CountryPackManifest;
use App\Modules\People\Payroll\Data\PayrollCalculationContext;
use App\Modules\People\Payroll\Data\PayrollCalculationResult;
use App\Modules\People\Payroll\Data\PayrollProposedResultLine;
use App\Modules\People\Payroll\Data\ProfileSchema;
use App\Modules\People\Payroll\Exceptions\ClosedPayrollRunException;
use App\Modules\People\Payroll\Models\PayrollCalendar;
use App\Modules\People\Payroll\Models\PayrollEmployeeStatutoryProfile;
use App\Modules\People\Payroll\Models\PayrollEmployerStatutoryProfile;
use App\Modules\People\Payroll\Models\PayrollInput;
use App\Modules\People\Payroll\Models\PayrollPayItem;
use App\Modules\People\Payroll\Models\PayrollPayItemClassification;
use App\Modules\People\Payroll\Models\PayrollPdfArtifact;
use App\Modules\People\Payroll\Models\PayrollPeriod;
use App\Modules\People\Payroll\Models\PayrollResultLine;
use App\Modules\People\Payroll\Models\PayrollRun;
use App\Modules\People\Payroll\Models\PayrollRunParticipant;
use App\Modules\People\Payroll\Models\PayrollStatutoryRuleRow;
use App\Modules\People\Payroll\Models\PayrollStatutoryRuleSet;
use App\Modules\People\Payroll\Services\PayItemClassifier;
use App\Modules\People\Payroll\Services\PayrollBankPaymentExportBuilder;
use App\Modules\People\Payroll\Services\PayrollCountryPackRegistry;
use App\Modules\People\Payroll\Services\PayrollEmployerCostReportBuilder;
use App\Modules\People\Payroll\Services\PayrollLockAuditReportBuilder;
use App\Modules\People\Payroll\Services\PayrollOperationalCsvExportBuilder;
use App\Modules\People\Payroll\Services\PayrollPayslipBuilder;
use App\Modules\People\Payroll\Services\PayrollPdfReportJobFactory;
use App\Modules\People\Payroll\Services\PayrollRunCalculator;
use App\Modules\People\Payroll\Services\PayrollStatutoryContributionReportBuilder;
use App\Modules\People\Payroll\Services\PayrollSummaryReportBuilder;
use App\Modules\People\Payroll\Services\StatutoryProfileResolver;
use App\Modules\People\Payroll\Services\StatutoryRuleSetResolver;
use Illuminate\Support\Carbon;

defined('PAYROLL_MY_PACK') || define('PAYROLL_MY_PACK', 'belimbing/payroll-my');
defined('PAYROLL_DEV_VERSION') || define('PAYROLL_DEV_VERSION', '2026.dev');
const PAYROLL_COUNTRY_PACK_VERSION = '2026.1';
const PAYROLL_PERIOD_START = '2026-01-01';
const PAYROLL_PERIOD_END = '2026-01-31';
const ZERO_AMOUNT = '0.0000';
const TEST_COUNTRY_PACK_EMPLOYEE_AMOUNT = '110.0000';
const TEST_COUNTRY_PACK_EMPLOYER_AMOUNT = '130.0000';
const BASIC_SALARY_AMOUNT = '3000.0000';
const PAYSLIP_BASIC_SALARY_AMOUNT = '2500.0000';
const BONUS_AMOUNT = '1000.0000';
const EPF_EMPLOYEE_RATE = '0.11000000';
const EPF_EMPLOYER_RATE = '0.13000000';
const EPF_EMPLOYEE_AMOUNT = '330.0000';
const EPF_EMPLOYER_AMOUNT = '390.0000';
const HRD_LEVY_AMOUNT = '30.0000';
const EMPLOYEE_DEDUCTION_AMOUNT = '125.0000';
const STANDARD_NET_PAY_AMOUNT = '2670.0000';
const SUMMARY_NET_PAY_AMOUNT = '2625.0000';
const TRAVEL_CLAIM_LABEL = 'Travel Claim';
const ADVANCE_RECOVERY_LABEL = 'Advance Recovery';
const EPF_EMPLOYEE_CONTRIBUTION_LABEL = 'EPF Employee Contribution';
const EPF_EMPLOYER_CONTRIBUTION_LABEL = 'EPF Employer Contribution';
const HRD_LEVY_LABEL = 'HRD Levy';
const NET_PAY_LABEL = 'Net Pay';
const TEST_BANK_NAME = 'Test Bank';
const PAYROLL_PAYSLIP_TEMPLATE_VERSION = 'payroll-payslip@v1';
const PAYROLL_PAYSLIP_ARTIFACT_PATH = 'pdf-artifacts/payroll/payslip-MY-2026-01-PDF-ARTIFACT.pdf';

function createPayrollCoreTestCountryPack(string $countryIso = 'SG'): PayrollCountryPack
{
    return new class($countryIso) implements CalculatesPayrollRun, ClassifiesPayrollPayItems, PayrollCountryPack, ProvidesPayrollExports, ProvidesPayrollProfileSchemas
    {
        public function __construct(private readonly string $countryIso) {}

        public function manifest(): CountryPackManifest
        {
            return new CountryPackManifest(
                countryIso: $this->countryIso,
                packIdentifier: 'test/payroll-'.strtolower($this->countryIso),
                packVersion: 'test.1',
                supportedCoreContracts: [PayrollCountryPackRegistry::CORE_CONTRACT_VERSION],
                statutoryDataVersions: ['test.1'],
            );
        }

        public function profileSchemas(): ProvidesPayrollProfileSchemas
        {
            return $this;
        }

        public function employerSchema(): ProfileSchema
        {
            return new ProfileSchema($this->countryIso, 'employer', 'test', 'test.1', []);
        }

        public function employeeSchema(): ProfileSchema
        {
            return new ProfileSchema($this->countryIso, 'employee', 'test', 'test.1', []);
        }

        public function payItemClassifier(): ClassifiesPayrollPayItems
        {
            return $this;
        }

        public function classificationsFor(PayrollPayItem $payItem, Carbon|string $onDate): array
        {
            return [];
        }

        public function calculator(): CalculatesPayrollRun
        {
            return $this;
        }

        public function calculate(PayrollCalculationContext $context): PayrollCalculationResult
        {
            return new PayrollCalculationResult(
                resultLines: [
                    new PayrollProposedResultLine(
                        lineType: PayrollResultLine::TYPE_EMPLOYEE_CONTRIBUTION,
                        code: 'test_employee_contribution',
                        label: 'Test Employee Contribution',
                        amount: TEST_COUNTRY_PACK_EMPLOYEE_AMOUNT,
                        currency: $context->run->currency,
                        sourceRule: 'test-contribution-rule',
                        sourceVersion: 'test.1',
                        explanation: ['pay_date' => $context->payDate()],
                    ),
                    new PayrollProposedResultLine(
                        lineType: PayrollResultLine::TYPE_EMPLOYER_CONTRIBUTION,
                        code: 'test_employer_contribution',
                        label: 'Test Employer Contribution',
                        amount: TEST_COUNTRY_PACK_EMPLOYER_AMOUNT,
                        currency: $context->run->currency,
                        sourceRule: 'test-contribution-rule',
                        sourceVersion: 'test.1',
                        explanation: ['country_iso' => $context->countryIso()],
                    ),
                ],
                warnings: [['level' => 'info', 'message' => 'Test warning.']],
                metadata: ['pack_identifier' => 'test/payroll-'.strtolower($this->countryIso)],
            );
        }

        public function exports(): ProvidesPayrollExports
        {
            return $this;
        }

        public function definitions(): array
        {
            return [];
        }
    };
}

function createPayrollCoreRun(string $runCode = 'MY-2026-01-MAIN'): array
{
    $company = Company::query()->findOrFail(Company::LICENSEE_ID);
    $employee = Employee::factory()->create(['company_id' => $company->id]);
    $calendar = PayrollCalendar::query()->create([
        'company_id' => $company->id,
        'code' => 'CAL-'.$runCode,
        'name' => 'Malaysia Monthly Payroll',
        'country_iso' => 'MY',
        'currency' => 'MYR',
        'frequency' => 'monthly',
        'status' => 'active',
    ]);
    $period = PayrollPeriod::query()->create([
        'payroll_calendar_id' => $calendar->id,
        'code' => '2026-01',
        'name' => 'January 2026',
        'starts_on' => PAYROLL_PERIOD_START,
        'ends_on' => PAYROLL_PERIOD_END,
        'pay_date' => PAYROLL_PERIOD_END,
        'status' => 'open',
    ]);
    $run = PayrollRun::query()->create([
        'company_id' => $company->id,
        'payroll_calendar_id' => $calendar->id,
        'payroll_period_id' => $period->id,
        'code' => $runCode,
        'name' => 'January 2026 Main Payroll',
        'status' => PayrollRun::STATUS_DRAFT,
        'currency' => 'MYR',
    ]);
    $participant = PayrollRunParticipant::query()->create([
        'payroll_run_id' => $run->id,
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'status' => 'included',
        'currency' => 'MYR',
    ]);

    return [$run, $participant, $employee];
}

function createPayrollInputRecord(PayrollRun $run, PayrollRunParticipant $participant, Employee $employee, array $attributes): PayrollInput
{
    return PayrollInput::query()->create([
        'payroll_run_id' => $run->id,
        'payroll_run_participant_id' => $participant->id,
        'employee_id' => $employee->id,
        'currency' => 'MYR',
        ...$attributes,
    ]);
}

function createPayrollInputRecords(PayrollRun $run, PayrollRunParticipant $participant, Employee $employee, array $inputs): void
{
    foreach ($inputs as $input) {
        createPayrollInputRecord($run, $participant, $employee, $input);
    }
}

function createPayrollResultLineRecord(PayrollRun $run, PayrollRunParticipant $participant, Employee $employee, array $attributes): PayrollResultLine
{
    return PayrollResultLine::query()->create([
        'payroll_run_id' => $run->id,
        'payroll_run_participant_id' => $participant->id,
        'employee_id' => $employee->id,
        'currency' => 'MYR',
        ...$attributes,
    ]);
}

function createPayrollResultLineRecords(PayrollRun $run, PayrollRunParticipant $participant, Employee $employee, array $lines): void
{
    foreach ($lines as $line) {
        createPayrollResultLineRecord($run, $participant, $employee, $line);
    }
}

function findParticipantResultLine(PayrollRunParticipant $participant, string $code): PayrollResultLine
{
    return PayrollResultLine::query()
        ->where('payroll_run_participant_id', $participant->id)
        ->where('code', $code)
        ->firstOrFail();
}

function createMalaysiaEmployerStatutoryProfile(Company $company, array $attributes = []): PayrollEmployerStatutoryProfile
{
    return PayrollEmployerStatutoryProfile::query()->create([
        'company_id' => $company->id,
        'country_iso' => 'MY',
        'source_pack' => PAYROLL_MY_PACK,
        'source_version' => PAYROLL_DEV_VERSION,
        'effective_from' => PAYROLL_PERIOD_START,
        'profile_data' => ['hrd_levy_applicable' => true],
        'validation_messages' => [],
        ...$attributes,
    ]);
}

function createMalaysiaEmployeeStatutoryProfile(Company $company, Employee $employee, array $attributes = []): PayrollEmployeeStatutoryProfile
{
    return PayrollEmployeeStatutoryProfile::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'country_iso' => 'MY',
        'source_pack' => PAYROLL_MY_PACK,
        'source_version' => PAYROLL_DEV_VERSION,
        'effective_from' => PAYROLL_PERIOD_START,
        'validation_messages' => [],
        ...$attributes,
    ]);
}

function createPayrollPayItemRecord(Company $company, array $attributes): PayrollPayItem
{
    return PayrollPayItem::query()->create([
        'company_id' => $company->id,
        'status' => 'active',
        ...$attributes,
    ]);
}

function createMalaysiaPayItemClassifications(PayrollPayItem $payItem, array $classifications): void
{
    foreach ($classifications as $classificationKey => $classificationValue) {
        PayrollPayItemClassification::query()->create([
            'payroll_pay_item_id' => $payItem->id,
            'country_iso' => 'MY',
            'classification_key' => $classificationKey,
            'classification_value' => $classificationValue,
            'effective_from' => PAYROLL_PERIOD_START,
            'source_pack' => PAYROLL_MY_PACK,
            'source_version' => PAYROLL_DEV_VERSION,
        ]);
    }
}

function createMalaysiaPayItemRecord(Company $company, array $attributes, array $classifications): PayrollPayItem
{
    $payItem = createPayrollPayItemRecord($company, $attributes);
    createMalaysiaPayItemClassifications($payItem, $classifications);

    return $payItem;
}

function createMalaysiaRuleSet(array $attributes): PayrollStatutoryRuleSet
{
    return PayrollStatutoryRuleSet::query()->create([
        'country_iso' => 'MY',
        'source_pack' => PAYROLL_MY_PACK,
        'source_version' => PAYROLL_DEV_VERSION,
        'effective_from' => PAYROLL_PERIOD_START,
        'rounding_policy' => ['mode' => 'ceiling', 'precision' => '0.01'],
        ...$attributes,
    ]);
}

function createMalaysiaRuleRow(PayrollStatutoryRuleSet $ruleSet, array $attributes): PayrollStatutoryRuleRow
{
    return PayrollStatutoryRuleRow::query()->create([
        'payroll_statutory_rule_set_id' => $ruleSet->id,
        'min_wage' => ZERO_AMOUNT,
        'max_wage' => null,
        ...$attributes,
    ]);
}

function seedMalaysiaStandardStatutoryRuleSets(): void
{
    $epfRuleSet = createMalaysiaRuleSet([
        'rule_key' => 'epf_contribution_schedule',
        'name' => 'EPF dev test schedule',
    ]);
    createMalaysiaRuleRow($epfRuleSet, [
        'sort_order' => 10,
        'row_key' => 'standard',
        'employee_rate' => EPF_EMPLOYEE_RATE,
        'employer_rate' => EPF_EMPLOYER_RATE,
    ]);

    $socsoRuleSet = createMalaysiaRuleSet([
        'rule_key' => 'socso_contribution_schedule',
        'name' => 'SOCSO dev test schedule',
    ]);
    createMalaysiaRuleRow($socsoRuleSet, [
        'sort_order' => 10,
        'row_key' => 'standard',
        'employee_rate' => '0.00500000',
        'employer_rate' => '0.01750000',
    ]);

    $eisRuleSet = createMalaysiaRuleSet([
        'rule_key' => 'eis_contribution_schedule',
        'name' => 'EIS dev test schedule',
    ]);
    createMalaysiaRuleRow($eisRuleSet, [
        'sort_order' => 10,
        'row_key' => 'standard',
        'employee_rate' => '0.00200000',
        'employer_rate' => '0.00200000',
    ]);

    $hrdLevyRuleSet = createMalaysiaRuleSet([
        'rule_key' => 'hrd_levy_schedule',
        'name' => 'HRD levy dev test schedule',
    ]);
    createMalaysiaRuleRow($hrdLevyRuleSet, [
        'sort_order' => 10,
        'row_key' => 'standard',
        'levy_rate' => '0.01000000',
    ]);
}

function seedMalaysiaCategoryStatutoryRuleSets(): void
{
    $epfRuleSet = createMalaysiaRuleSet([
        'rule_key' => 'epf_contribution_schedule',
        'name' => 'EPF category schedule',
    ]);
    createMalaysiaRuleRow($epfRuleSet, [
        'sort_order' => 10,
        'row_key' => 'citizen-standard',
        'employee_rate' => EPF_EMPLOYEE_RATE,
        'employer_rate' => EPF_EMPLOYER_RATE,
        'row_data' => ['epf_category' => 'citizen'],
    ]);
    createMalaysiaRuleRow($epfRuleSet, [
        'sort_order' => 20,
        'row_key' => 'foreign-worker-fixed',
        'employee_amount' => '20.0000',
        'employer_amount' => '40.0000',
        'row_data' => ['epf_category' => 'foreign_worker'],
    ]);

    $socsoRuleSet = createMalaysiaRuleSet([
        'rule_key' => 'socso_contribution_schedule',
        'name' => 'SOCSO category schedule',
    ]);
    createMalaysiaRuleRow($socsoRuleSet, [
        'sort_order' => 10,
        'row_key' => 'foreign-worker',
        'employee_rate' => '0.00500000',
        'employer_rate' => '0.01750000',
        'row_data' => ['socso_category' => 'foreign_worker'],
    ]);

    createMalaysiaRuleSet([
        'rule_key' => 'eis_contribution_schedule',
        'name' => 'EIS category schedule',
    ]);

    $hrdRuleSet = createMalaysiaRuleSet([
        'rule_key' => 'hrd_levy_schedule',
        'name' => 'HRD category schedule',
    ]);
    createMalaysiaRuleRow($hrdRuleSet, [
        'sort_order' => 10,
        'row_key' => 'foreign-worker',
        'levy_rate' => '0.01000000',
        'row_data' => ['employee_category' => 'foreign_worker'],
    ]);
}

function expectMalaysiaStandardStatutoryOutcome(PayrollRunParticipant $participant): void
{
    $employeeEpf = findParticipantResultLine($participant, 'my_epf_employee');
    $employerEpf = findParticipantResultLine($participant, 'my_epf_employer');
    $employeeSocso = findParticipantResultLine($participant, 'my_socso_employee');
    $employerSocso = findParticipantResultLine($participant, 'my_socso_employer');
    $employeeEis = findParticipantResultLine($participant, 'my_eis_employee');
    $employerEis = findParticipantResultLine($participant, 'my_eis_employer');
    $hrdLevy = findParticipantResultLine($participant, 'my_hrd_levy');

    expect($employeeEpf)
        ->line_type->toBe(PayrollResultLine::TYPE_EMPLOYEE_CONTRIBUTION)
        ->amount->toBe(EPF_EMPLOYEE_AMOUNT)
        ->and($employerEpf)
        ->line_type->toBe(PayrollResultLine::TYPE_EMPLOYER_CONTRIBUTION)
        ->amount->toBe(EPF_EMPLOYER_AMOUNT)
        ->and($employeeSocso)->amount->toBe('15.0000')
        ->and($employerSocso)->amount->toBe('52.5000')
        ->and($employeeEis)->amount->toBe('6.0000')
        ->and($employerEis)->amount->toBe('6.0000')
        ->and($hrdLevy)
        ->line_type->toBe(PayrollResultLine::TYPE_EMPLOYER_LEVY)
        ->amount->toBe(HRD_LEVY_AMOUNT)
        ->and($employeeEpf->explanation)->toMatchArray([
            'wage_base' => BASIC_SALARY_AMOUNT,
            'rule_row_key' => 'standard',
            'share' => 'employee',
        ])
        ->and($participant->refresh())
        ->gross_pay->toBe(BASIC_SALARY_AMOUNT)
        ->total_deductions->toBe('351.0000')
        ->total_reimbursements->toBe('80.0000')
        ->net_pay->toBe('2729.0000');
}

function expectMalaysiaCategoryStatutoryOutcome(PayrollRunParticipant $participant): void
{
    $employeeEpf = findParticipantResultLine($participant, 'my_epf_employee');
    $employerEpf = findParticipantResultLine($participant, 'my_epf_employer');
    $employeeSocso = findParticipantResultLine($participant, 'my_socso_employee');
    $hrdLevy = findParticipantResultLine($participant, 'my_hrd_levy');

    expect($employeeEpf)
        ->amount->toBe('20.0000')
        ->and($employerEpf)->amount->toBe('40.0000')
        ->and($employeeEpf->explanation)->toMatchArray([
            'wage_base' => '4000.0000',
            'wage_base_keys' => ['epf_wage_base', 'statutory_wage_base'],
            'rule_row_key' => 'foreign-worker-fixed',
            'employee_category' => [
                'employee_category' => 'foreign_worker',
                'citizenship_status' => 'foreign_worker',
                'age_category' => 'under_60',
                'epf_category' => 'foreign_worker',
                'socso_category' => 'foreign_worker',
                'eis_category' => 'not_applicable',
            ],
        ])
        ->and($employeeSocso)
        ->amount->toBe('15.0000')
        ->and($employeeSocso->explanation)->toMatchArray([
            'wage_base' => BASIC_SALARY_AMOUNT,
            'wage_base_keys' => ['socso_wage_base', 'statutory_wage_base'],
            'rule_row_key' => 'foreign-worker',
        ])
        ->and(PayrollResultLine::query()->where('payroll_run_participant_id', $participant->id)->where('code', 'my_eis_employee')->exists())->toBeFalse()
        ->and($hrdLevy)
        ->amount->toBe(HRD_LEVY_AMOUNT)
        ->and($hrdLevy->explanation)->toMatchArray([
            'wage_base' => BASIC_SALARY_AMOUNT,
            'wage_base_keys' => ['hrd_levy_wage_base', 'statutory_wage_base'],
            'rule_row_key' => 'foreign-worker',
        ])
        ->and($participant->refresh())
        ->gross_pay->toBe('4000.0000')
        ->total_deductions->toBe('35.0000')
        ->net_pay->toBe('3965.0000');
}

test('neutral payroll core calculates gross deductions reimbursements and net pay', function (): void {
    [$run, $participant, $employee] = createPayrollCoreRun();

    PayrollInput::query()->create([
        'payroll_run_id' => $run->id,
        'payroll_run_participant_id' => $participant->id,
        'employee_id' => $employee->id,
        'pay_item_code' => 'basic_salary',
        'label' => 'Basic Salary',
        'input_type' => PayrollInput::TYPE_EARNING,
        'amount' => '3000.1000',
        'currency' => 'MYR',
    ]);
    PayrollInput::query()->create([
        'payroll_run_id' => $run->id,
        'payroll_run_participant_id' => $participant->id,
        'employee_id' => $employee->id,
        'pay_item_code' => 'bonus',
        'label' => 'Bonus',
        'input_type' => PayrollInput::TYPE_EARNING,
        'amount' => '499.9000',
        'currency' => 'MYR',
    ]);
    PayrollInput::query()->create([
        'payroll_run_id' => $run->id,
        'payroll_run_participant_id' => $participant->id,
        'employee_id' => $employee->id,
        'pay_item_code' => 'advance_recovery',
        'label' => ADVANCE_RECOVERY_LABEL,
        'input_type' => PayrollInput::TYPE_DEDUCTION,
        'amount' => '125.2500',
        'currency' => 'MYR',
    ]);
    PayrollInput::query()->create([
        'payroll_run_id' => $run->id,
        'payroll_run_participant_id' => $participant->id,
        'employee_id' => $employee->id,
        'pay_item_code' => 'travel_claim',
        'label' => TRAVEL_CLAIM_LABEL,
        'input_type' => PayrollInput::TYPE_REIMBURSEMENT,
        'amount' => '80.0000',
        'currency' => 'MYR',
    ]);

    $calculated = app(PayrollRunCalculator::class)->calculate($run);

    expect($calculated->status)->toBe(PayrollRun::STATUS_CALCULATED)
        ->and($calculated->calculated_at)->not()->toBeNull()
        ->and($participant->refresh())
        ->gross_pay->toBe('3500.0000')
        ->total_deductions->toBe('125.2500')
        ->total_reimbursements->toBe('80.0000')
        ->net_pay->toBe('3454.7500');

    expect(PayrollResultLine::query()->where('payroll_run_id', $run->id)->pluck('line_type')->all())
        ->toEqualCanonicalizing([
            PayrollResultLine::TYPE_EARNING,
            PayrollResultLine::TYPE_EARNING,
            PayrollResultLine::TYPE_EMPLOYEE_DEDUCTION,
            PayrollResultLine::TYPE_REIMBURSEMENT,
            PayrollResultLine::TYPE_NET_PAY,
        ]);

    $netPayLine = PayrollResultLine::query()
        ->where('payroll_run_id', $run->id)
        ->where('line_type', PayrollResultLine::TYPE_NET_PAY)
        ->firstOrFail();

    expect($netPayLine)
        ->amount->toBe('3454.7500')
        ->source_rule->toBe('payroll-core-neutral-net-pay')
        ->and($netPayLine->explanation)->toMatchArray([
            'gross_pay' => '3500.0000',
            'total_deductions' => '125.2500',
            'total_reimbursements' => '80.0000',
        ]);

    $this->assertDatabaseHas('people_payroll_run_audit_events', [
        'payroll_run_id' => $run->id,
        'action' => 'calculated',
    ]);
});

test('payroll core persists registered country pack result lines before net pay', function (): void {
    app(PayrollCountryPackRegistry::class)->register(createPayrollCoreTestCountryPack('SG'));
    [$run, $participant, $employee] = createPayrollCoreRun('SG-2026-01-MAIN');
    $run->calendar->forceFill(['country_iso' => 'SG'])->save();

    PayrollInput::query()->create([
        'payroll_run_id' => $run->id,
        'payroll_run_participant_id' => $participant->id,
        'employee_id' => $employee->id,
        'pay_item_code' => 'basic_salary',
        'label' => 'Basic Salary',
        'input_type' => PayrollInput::TYPE_EARNING,
        'amount' => BONUS_AMOUNT,
        'currency' => 'MYR',
    ]);

    app(PayrollRunCalculator::class)->calculate($run->refresh());

    expect($participant->refresh())
        ->gross_pay->toBe(BONUS_AMOUNT)
        ->total_deductions->toBe(TEST_COUNTRY_PACK_EMPLOYEE_AMOUNT)
        ->net_pay->toBe('890.0000');

    $resultLines = PayrollResultLine::query()
        ->where('payroll_run_participant_id', $participant->id)
        ->orderBy('id')
        ->get();

    expect($resultLines->pluck('code')->all())->toBe([
        'basic_salary',
        'test_employee_contribution',
        'test_employer_contribution',
        'net_pay',
    ])
        ->and($resultLines->firstWhere('code', 'test_employee_contribution')->line_type)
        ->toBe(PayrollResultLine::TYPE_EMPLOYEE_CONTRIBUTION)
        ->and($resultLines->firstWhere('code', 'test_employer_contribution')->line_type)
        ->toBe(PayrollResultLine::TYPE_EMPLOYER_CONTRIBUTION)
        ->and($resultLines->firstWhere('code', 'net_pay')->explanation)
        ->toMatchArray([
            'total_deductions' => TEST_COUNTRY_PACK_EMPLOYEE_AMOUNT,
            'country_pack' => ['pack_identifier' => 'test/payroll-sg'],
            'country_pack_warnings' => [['level' => 'info', 'message' => 'Test warning.']],
        ])
        ->and($run->refresh()->auditEvents()->latest('id')->first()->payload['country_pack'])
        ->toMatchArray([
            'country_iso' => 'SG',
            'pack_identifier' => 'test/payroll-sg',
            'pack_version' => 'test.1',
        ]);
});

test('malaysia pack calculates epf socso eis and hrd levy from classified statutory wages', function (): void {
    [$run, $participant, $employee] = createPayrollCoreRun('MY-2026-01-EPF');
    $company = Company::query()->findOrFail(Company::LICENSEE_ID);

    createMalaysiaEmployerStatutoryProfile($company);
    createMalaysiaPayItemRecord($company, [
        'code' => 'basic_salary',
        'name' => 'Basic Salary',
        'input_type' => PayrollInput::TYPE_EARNING,
    ], ['statutory_wage_base' => 'ordinary_wage']);
    createMalaysiaPayItemRecord($company, [
        'code' => 'travel_claim',
        'name' => TRAVEL_CLAIM_LABEL,
        'input_type' => PayrollInput::TYPE_REIMBURSEMENT,
    ], ['statutory_wage_base' => 'excluded']);
    seedMalaysiaStandardStatutoryRuleSets();

    createPayrollInputRecords($run, $participant, $employee, [
        [
            'pay_item_code' => 'basic_salary',
            'label' => 'Basic Salary',
            'input_type' => PayrollInput::TYPE_EARNING,
            'amount' => BASIC_SALARY_AMOUNT,
        ],
        [
            'pay_item_code' => 'travel_claim',
            'label' => TRAVEL_CLAIM_LABEL,
            'input_type' => PayrollInput::TYPE_REIMBURSEMENT,
            'amount' => '80.0000',
        ],
    ]);

    app(PayrollRunCalculator::class)->calculate($run->refresh());

    expectMalaysiaStandardStatutoryOutcome($participant);
});

test('malaysia pack uses component wage bases and employee category rule rows', function (): void {
    [$run, $participant, $employee] = createPayrollCoreRun('MY-2026-01-CATEGORY');
    $company = Company::query()->findOrFail(Company::LICENSEE_ID);

    createMalaysiaEmployerStatutoryProfile($company);
    createMalaysiaEmployeeStatutoryProfile($company, $employee, [
        'profile_data' => [
            'citizenship_status' => 'foreign_worker',
            'epf_category' => 'foreign_worker',
            'socso_category' => 'foreign_worker',
            'eis_category' => 'not_applicable',
            'age_category' => 'under_60',
        ],
    ]);

    createMalaysiaPayItemRecord($company, [
        'code' => 'category_basic_salary',
        'name' => 'Category Basic Salary',
        'input_type' => PayrollInput::TYPE_EARNING,
    ], [
        'epf_wage_base' => 'ordinary_wage',
        'socso_wage_base' => 'ordinary_wage',
        'eis_wage_base' => 'excluded',
        'hrd_levy_wage_base' => 'ordinary_wage',
    ]);

    createMalaysiaPayItemRecord($company, [
        'code' => 'category_bonus',
        'name' => 'Category Bonus',
        'input_type' => PayrollInput::TYPE_EARNING,
    ], [
        'epf_wage_base' => 'additional_wage',
        'socso_wage_base' => 'excluded',
        'eis_wage_base' => 'excluded',
        'hrd_levy_wage_base' => 'excluded',
    ]);
    seedMalaysiaCategoryStatutoryRuleSets();

    createPayrollInputRecords($run, $participant, $employee, [
        [
            'pay_item_code' => 'category_basic_salary',
            'label' => 'Category Basic Salary',
            'input_type' => PayrollInput::TYPE_EARNING,
            'amount' => BASIC_SALARY_AMOUNT,
        ],
        [
            'pay_item_code' => 'category_bonus',
            'label' => 'Category Bonus',
            'input_type' => PayrollInput::TYPE_EARNING,
            'amount' => BONUS_AMOUNT,
        ],
    ]);

    app(PayrollRunCalculator::class)->calculate($run->refresh());

    expectMalaysiaCategoryStatutoryOutcome($participant);
});

test('malaysia pack blocks calculation when an applicable required schedule is missing', function (): void {
    [$run, $participant, $employee] = createPayrollCoreRun('MY-2026-01-MISSING-RULE');
    $company = Company::query()->findOrFail(Company::LICENSEE_ID);

    $payItem = PayrollPayItem::query()->create([
        'company_id' => $company->id,
        'code' => 'missing_rule_basic_salary',
        'name' => 'Missing Rule Basic Salary',
        'input_type' => PayrollInput::TYPE_EARNING,
        'status' => 'active',
    ]);
    PayrollPayItemClassification::query()->create([
        'payroll_pay_item_id' => $payItem->id,
        'country_iso' => 'MY',
        'classification_key' => 'epf_wage_base',
        'classification_value' => 'ordinary_wage',
        'effective_from' => PAYROLL_PERIOD_START,
        'source_pack' => PAYROLL_MY_PACK,
        'source_version' => PAYROLL_DEV_VERSION,
    ]);
    PayrollInput::query()->create([
        'payroll_run_id' => $run->id,
        'payroll_run_participant_id' => $participant->id,
        'employee_id' => $employee->id,
        'pay_item_code' => 'missing_rule_basic_salary',
        'label' => 'Missing Rule Basic Salary',
        'input_type' => PayrollInput::TYPE_EARNING,
        'amount' => BASIC_SALARY_AMOUNT,
        'currency' => 'MYR',
    ]);

    app(PayrollRunCalculator::class)->calculate($run->refresh());
})->throws(BlbDataContractException::class);

test('payroll run lifecycle records review approval close and void audit events', function (): void {
    [$reviewedRun] = createPayrollCoreRun('MY-2026-01-REVIEWED');
    $reviewedRun->markReviewed();
    $reviewedRun->approve();
    $reviewedRun->close();

    expect($reviewedRun->refresh())
        ->status->toBe(PayrollRun::STATUS_CLOSED)
        ->reviewed_at->not()->toBeNull()
        ->approved_at->not()->toBeNull()
        ->closed_at->not()->toBeNull();

    expect($reviewedRun->auditEvents()->pluck('action')->all())
        ->toEqual(['reviewed', 'approved', 'closed']);

    [$voidedRun] = createPayrollCoreRun('MY-2026-01-VOIDED');
    $voidedRun->void();

    expect($voidedRun->refresh())
        ->status->toBe(PayrollRun::STATUS_VOIDED)
        ->voided_at->not()->toBeNull();

    $this->assertDatabaseHas('people_payroll_run_audit_events', [
        'payroll_run_id' => $voidedRun->id,
        'action' => 'voided',
    ]);
});

test('basic payslip snapshot is generated from payroll result lines', function (): void {
    [$run, $participant, $employee] = createPayrollCoreRun('MY-2026-01-PAYSLIP');

    PayrollInput::query()->create([
        'payroll_run_id' => $run->id,
        'payroll_run_participant_id' => $participant->id,
        'employee_id' => $employee->id,
        'pay_item_code' => 'basic_salary',
        'label' => 'Basic Salary',
        'input_type' => PayrollInput::TYPE_EARNING,
        'amount' => PAYSLIP_BASIC_SALARY_AMOUNT,
        'currency' => 'MYR',
    ]);
    PayrollInput::query()->create([
        'payroll_run_id' => $run->id,
        'payroll_run_participant_id' => $participant->id,
        'employee_id' => $employee->id,
        'pay_item_code' => 'advance_recovery',
        'label' => ADVANCE_RECOVERY_LABEL,
        'input_type' => PayrollInput::TYPE_DEDUCTION,
        'amount' => '100.0000',
        'currency' => 'MYR',
    ]);

    app(PayrollRunCalculator::class)->calculate($run);

    $payslip = app(PayrollPayslipBuilder::class)->build($participant->refresh());

    expect($payslip['employee'])->toMatchArray([
        'id' => $employee->id,
        'number' => $employee->employee_number,
        'name' => $employee->displayName(),
    ])
        ->and($payslip['period'])->toMatchArray([
            'code' => '2026-01',
            'name' => 'January 2026',
            'starts_on' => PAYROLL_PERIOD_START,
            'ends_on' => PAYROLL_PERIOD_END,
            'pay_date' => PAYROLL_PERIOD_END,
        ])
        ->and($payslip['summary'])->toMatchArray([
            'gross_pay' => PAYSLIP_BASIC_SALARY_AMOUNT,
            'total_deductions' => '100.0000',
            'total_reimbursements' => ZERO_AMOUNT,
            'net_pay' => '2400.0000',
        ])
        ->and(array_column($payslip['lines'], 'type'))->toBe([
            PayrollResultLine::TYPE_EARNING,
            PayrollResultLine::TYPE_EMPLOYEE_DEDUCTION,
            PayrollResultLine::TYPE_NET_PAY,
        ]);
});

test('payslip snapshot separates employee statutory and employer cost sections', function (): void {
    [$run, $participant, $employee] = createPayrollCoreRun('MY-2026-01-PAYSLIP-STATUTORY');

    createPayrollResultLineRecords($run, $participant, $employee, [
        [
            'line_type' => PayrollResultLine::TYPE_EARNING,
            'code' => 'basic_salary',
            'label' => 'Basic Salary',
            'amount' => BASIC_SALARY_AMOUNT,
        ],
        [
            'line_type' => PayrollResultLine::TYPE_EMPLOYEE_CONTRIBUTION,
            'code' => 'my_epf_employee',
            'label' => EPF_EMPLOYEE_CONTRIBUTION_LABEL,
            'amount' => EPF_EMPLOYEE_AMOUNT,
        ],
        [
            'line_type' => PayrollResultLine::TYPE_EMPLOYER_CONTRIBUTION,
            'code' => 'my_epf_employer',
            'label' => EPF_EMPLOYER_CONTRIBUTION_LABEL,
            'amount' => EPF_EMPLOYER_AMOUNT,
        ],
        [
            'line_type' => PayrollResultLine::TYPE_EMPLOYER_LEVY,
            'code' => 'my_hrd_levy',
            'label' => HRD_LEVY_LABEL,
            'amount' => HRD_LEVY_AMOUNT,
        ],
        [
            'line_type' => PayrollResultLine::TYPE_NET_PAY,
            'code' => 'net_pay',
            'label' => NET_PAY_LABEL,
            'amount' => STANDARD_NET_PAY_AMOUNT,
        ],
    ]);
    $participant->forceFill([
        'gross_pay' => BASIC_SALARY_AMOUNT,
        'total_deductions' => EPF_EMPLOYEE_AMOUNT,
        'total_reimbursements' => ZERO_AMOUNT,
        'net_pay' => STANDARD_NET_PAY_AMOUNT,
    ])->save();

    $payslip = app(PayrollPayslipBuilder::class)->build($participant->refresh());

    expect($payslip['sections']['employee_contributions'])->toHaveCount(1)
        ->and($payslip['sections']['employee_contributions'][0]['code'])->toBe('my_epf_employee')
        ->and($payslip['sections']['employer_contributions'])->toHaveCount(1)
        ->and($payslip['sections']['employer_contributions'][0]['code'])->toBe('my_epf_employer')
        ->and($payslip['sections']['employer_levies'])->toHaveCount(1)
        ->and($payslip['sections']['employer_levies'][0]['code'])->toBe('my_hrd_levy')
        ->and($payslip['summary'])->toMatchArray([
            'employer_contributions' => EPF_EMPLOYER_AMOUNT,
            'employer_levies' => HRD_LEVY_AMOUNT,
            'total_employer_cost' => '3420.0000',
        ]);
});

test('payslip pdf template renders from payroll payslip builder data', function (): void {
    [$run, $participant, $employee] = createPayrollCoreRun('MY-2026-01-PAYSLIP-PDF');

    createPayrollResultLineRecords($run, $participant, $employee, [
        [
            'line_type' => PayrollResultLine::TYPE_EARNING,
            'code' => 'basic_salary',
            'label' => 'Basic Salary',
            'amount' => BASIC_SALARY_AMOUNT,
        ],
        [
            'line_type' => PayrollResultLine::TYPE_EMPLOYER_CONTRIBUTION,
            'code' => 'my_epf_employer',
            'label' => EPF_EMPLOYER_CONTRIBUTION_LABEL,
            'amount' => EPF_EMPLOYER_AMOUNT,
        ],
        [
            'line_type' => PayrollResultLine::TYPE_NET_PAY,
            'code' => 'net_pay',
            'label' => NET_PAY_LABEL,
            'amount' => BASIC_SALARY_AMOUNT,
        ],
    ]);
    $participant->forceFill([
        'gross_pay' => BASIC_SALARY_AMOUNT,
        'net_pay' => BASIC_SALARY_AMOUNT,
    ])->save();

    $payslip = app(PayrollPayslipBuilder::class)->build($participant->refresh());
    $html = view('pdf.payroll.payslip', ['payslip' => $payslip])->render();

    expect($html)->toContain('Payslip for January 2026')
        ->and($html)->toContain('Basic Salary')
        ->and($html)->toContain(EPF_EMPLOYER_CONTRIBUTION_LABEL)
        ->and($html)->toContain('390.00')
        ->and($html)->toContain('not deducted from net pay');
});

test('employer cost report summarizes employer contributions and levies by run', function (): void {
    [$run, $participant, $employee] = createPayrollCoreRun('MY-2026-01-EMPLOYER-COST');

    foreach ([
        [PayrollResultLine::TYPE_EMPLOYER_CONTRIBUTION, 'my_epf_employer', EPF_EMPLOYER_CONTRIBUTION_LABEL, EPF_EMPLOYER_AMOUNT],
        [PayrollResultLine::TYPE_EMPLOYER_CONTRIBUTION, 'my_socso_employer', 'SOCSO Employer Contribution', '52.5000'],
        [PayrollResultLine::TYPE_EMPLOYER_LEVY, 'my_hrd_levy', HRD_LEVY_LABEL, HRD_LEVY_AMOUNT],
    ] as [$type, $code, $label, $amount]) {
        PayrollResultLine::query()->create([
            'payroll_run_id' => $run->id,
            'payroll_run_participant_id' => $participant->id,
            'employee_id' => $employee->id,
            'line_type' => $type,
            'code' => $code,
            'label' => $label,
            'amount' => $amount,
            'currency' => 'MYR',
        ]);
    }
    $participant->forceFill([
        'gross_pay' => BASIC_SALARY_AMOUNT,
        'total_reimbursements' => '80.0000',
    ])->save();

    $report = app(PayrollEmployerCostReportBuilder::class)->build($run->refresh());

    expect($report['run'])->toMatchArray([
        'code' => 'MY-2026-01-EMPLOYER-COST',
        'country_iso' => 'MY',
        'currency' => 'MYR',
    ])
        ->and($report['participants'])->toHaveCount(1)
        ->and($report['participants'][0])->toMatchArray([
            'employer_contributions' => '442.5000',
            'employer_levies' => HRD_LEVY_AMOUNT,
            'total_employer_cost' => '3552.5000',
        ])
        ->and($report['totals'])->toMatchArray([
            'gross_pay' => BASIC_SALARY_AMOUNT,
            'reimbursements' => '80.0000',
            'employer_contributions' => '442.5000',
            'employer_levies' => HRD_LEVY_AMOUNT,
            'total_employer_cost' => '3552.5000',
        ]);
});

test('payroll summary report builds renderable payroll summary data', function (): void {
    [$run, $participant, $employee] = createPayrollCoreRun('MY-2026-01-SUMMARY-REPORT');

    foreach ([
        [PayrollResultLine::TYPE_EARNING, 'basic_salary', 'Basic Salary', BASIC_SALARY_AMOUNT],
        [PayrollResultLine::TYPE_EMPLOYEE_DEDUCTION, 'advance_recovery', ADVANCE_RECOVERY_LABEL, EMPLOYEE_DEDUCTION_AMOUNT],
        [PayrollResultLine::TYPE_EMPLOYEE_CONTRIBUTION, 'my_epf_employee', EPF_EMPLOYEE_CONTRIBUTION_LABEL, EPF_EMPLOYEE_AMOUNT],
        [PayrollResultLine::TYPE_EMPLOYER_CONTRIBUTION, 'my_epf_employer', EPF_EMPLOYER_CONTRIBUTION_LABEL, EPF_EMPLOYER_AMOUNT],
        [PayrollResultLine::TYPE_EMPLOYER_LEVY, 'my_hrd_levy', HRD_LEVY_LABEL, HRD_LEVY_AMOUNT],
        [PayrollResultLine::TYPE_REIMBURSEMENT, 'travel_claim', TRAVEL_CLAIM_LABEL, '80.0000'],
        [PayrollResultLine::TYPE_NET_PAY, 'net_pay', NET_PAY_LABEL, SUMMARY_NET_PAY_AMOUNT],
    ] as [$type, $code, $label, $amount]) {
        PayrollResultLine::query()->create([
            'payroll_run_id' => $run->id,
            'payroll_run_participant_id' => $participant->id,
            'employee_id' => $employee->id,
            'line_type' => $type,
            'code' => $code,
            'label' => $label,
            'amount' => $amount,
            'currency' => 'MYR',
        ]);
    }
    $participant->forceFill([
        'gross_pay' => BASIC_SALARY_AMOUNT,
        'total_deductions' => '455.0000',
        'total_reimbursements' => '80.0000',
        'net_pay' => SUMMARY_NET_PAY_AMOUNT,
    ])->save();

    $report = app(PayrollSummaryReportBuilder::class)->build($run->refresh());
    $html = view('pdf.payroll.payroll-summary', ['report' => $report])->render();

    expect($report['totals'])->toMatchArray([
        'gross_pay' => BASIC_SALARY_AMOUNT,
        'employee_deductions' => EMPLOYEE_DEDUCTION_AMOUNT,
        'employee_contributions' => EPF_EMPLOYEE_AMOUNT,
        'reimbursements' => '80.0000',
        'net_pay' => SUMMARY_NET_PAY_AMOUNT,
        'employer_contributions' => EPF_EMPLOYER_AMOUNT,
        'employer_levies' => HRD_LEVY_AMOUNT,
    ])
        ->and($report['participants'][0])->toMatchArray([
            'gross_pay' => BASIC_SALARY_AMOUNT,
            'employee_deductions' => EMPLOYEE_DEDUCTION_AMOUNT,
            'employee_contributions' => EPF_EMPLOYEE_AMOUNT,
            'reimbursements' => '80.0000',
            'net_pay' => SUMMARY_NET_PAY_AMOUNT,
        ])
        ->and($html)->toContain('Payroll Summary')
        ->and($html)->toContain('MY-2026-01-SUMMARY-REPORT');
});

test('statutory contribution report groups contribution totals and renders template', function (): void {
    [$run, $participant, $employee] = createPayrollCoreRun('MY-2026-01-STATUTORY-REPORT');

    foreach ([
        [PayrollResultLine::TYPE_EMPLOYEE_CONTRIBUTION, 'my_epf_employee', EPF_EMPLOYEE_CONTRIBUTION_LABEL, EPF_EMPLOYEE_AMOUNT, 'epf_contribution_schedule'],
        [PayrollResultLine::TYPE_EMPLOYER_CONTRIBUTION, 'my_epf_employer', EPF_EMPLOYER_CONTRIBUTION_LABEL, EPF_EMPLOYER_AMOUNT, 'epf_contribution_schedule'],
        [PayrollResultLine::TYPE_EMPLOYER_LEVY, 'my_hrd_levy', HRD_LEVY_LABEL, HRD_LEVY_AMOUNT, 'hrd_levy_schedule'],
    ] as [$type, $code, $label, $amount, $sourceRule]) {
        PayrollResultLine::query()->create([
            'payroll_run_id' => $run->id,
            'payroll_run_participant_id' => $participant->id,
            'employee_id' => $employee->id,
            'line_type' => $type,
            'code' => $code,
            'label' => $label,
            'amount' => $amount,
            'currency' => 'MYR',
            'source_rule' => $sourceRule,
            'source_version' => PAYROLL_DEV_VERSION,
        ]);
    }

    $report = app(PayrollStatutoryContributionReportBuilder::class)->build($run->refresh());
    $html = view('pdf.payroll.employee-statutory-contribution', ['report' => $report])->render();

    expect($report['participants'][0]['lines'])->toHaveCount(3)
        ->and($report['totals_by_code'])->toContain([
            'code' => 'my_epf_employee',
            'label' => EPF_EMPLOYEE_CONTRIBUTION_LABEL,
            'type' => PayrollResultLine::TYPE_EMPLOYEE_CONTRIBUTION,
            'amount' => EPF_EMPLOYEE_AMOUNT,
        ])
        ->and($report['totals_by_code'])->toContain([
            'code' => 'my_hrd_levy',
            'label' => HRD_LEVY_LABEL,
            'type' => PayrollResultLine::TYPE_EMPLOYER_LEVY,
            'amount' => HRD_LEVY_AMOUNT,
        ])
        ->and($html)->toContain('Employee Statutory Contributions')
        ->and($html)->toContain('epf_contribution_schedule');
});

test('employer cost report template renders from report data', function (): void {
    [$run, $participant, $employee] = createPayrollCoreRun('MY-2026-01-EMPLOYER-COST-PDF');

    PayrollResultLine::query()->create([
        'payroll_run_id' => $run->id,
        'payroll_run_participant_id' => $participant->id,
        'employee_id' => $employee->id,
        'line_type' => PayrollResultLine::TYPE_EMPLOYER_CONTRIBUTION,
        'code' => 'my_epf_employer',
        'label' => EPF_EMPLOYER_CONTRIBUTION_LABEL,
        'amount' => EPF_EMPLOYER_AMOUNT,
        'currency' => 'MYR',
    ]);
    $participant->forceFill(['gross_pay' => BASIC_SALARY_AMOUNT])->save();

    $report = app(PayrollEmployerCostReportBuilder::class)->build($run->refresh());
    $html = view('pdf.payroll.employer-cost', ['report' => $report])->render();

    expect($html)->toContain('Employer Cost Report')
        ->and($html)->toContain('MY-2026-01-EMPLOYER-COST-PDF')
        ->and($html)->toContain('3,390.00');
});

test('payroll lock audit report summarizes lifecycle controls and audit events', function (): void {
    [$run, $participant, $employee] = createPayrollCoreRun('MY-2026-01-LOCK-AUDIT');

    PayrollResultLine::query()->create([
        'payroll_run_id' => $run->id,
        'payroll_run_participant_id' => $participant->id,
        'employee_id' => $employee->id,
        'line_type' => PayrollResultLine::TYPE_EARNING,
        'code' => 'basic_salary',
        'label' => 'Basic Salary',
        'amount' => BASIC_SALARY_AMOUNT,
        'currency' => 'MYR',
    ]);
    PayrollResultLine::query()->create([
        'payroll_run_id' => $run->id,
        'payroll_run_participant_id' => $participant->id,
        'employee_id' => $employee->id,
        'line_type' => PayrollResultLine::TYPE_NET_PAY,
        'code' => 'net_pay',
        'label' => NET_PAY_LABEL,
        'amount' => BASIC_SALARY_AMOUNT,
        'currency' => 'MYR',
    ]);
    $participant->forceFill([
        'gross_pay' => BASIC_SALARY_AMOUNT,
        'net_pay' => BASIC_SALARY_AMOUNT,
    ])->save();

    $run->markReviewed();
    $run->approve();
    $run->close();

    $report = app(PayrollLockAuditReportBuilder::class)->build($run->refresh());
    $html = view('pdf.payroll.lock-audit', ['report' => $report])->render();

    expect($report['lock_state'])->toMatchArray([
        'is_locked' => true,
        'is_reviewed' => true,
        'is_approved' => true,
    ])
        ->and($report['controls'])->toMatchArray([
            'participants_count' => 1,
            'result_lines_count' => 2,
            'audit_events_count' => 3,
        ])
        ->and($report['totals_by_line_type'])->toContain([
            'type' => PayrollResultLine::TYPE_EARNING,
            'count' => 1,
            'amount' => BASIC_SALARY_AMOUNT,
        ])
        ->and(array_column($report['audit_events'], 'action'))->toBe(['reviewed', 'approved', 'closed'])
        ->and($html)->toContain('Payroll Lock Audit Report')
        ->and($html)->toContain('MY-2026-01-LOCK-AUDIT')
        ->and($html)->toContain('Locked')
        ->and($html)->toContain('closed');
});

test('bank payment export placeholder produces a clearly marked review csv', function (): void {
    [$run, $participant, $employee] = createPayrollCoreRun('MY-2026-01-BANK-EXPORT');
    $employee->forceFill([
        'metadata' => [
            'payroll_bank' => [
                'bank_name' => TEST_BANK_NAME,
                'bank_account_number' => '1234567890',
            ],
        ],
    ])->save();
    $participant->forceFill([
        'net_pay' => PAYSLIP_BASIC_SALARY_AMOUNT,
        'currency' => 'MYR',
    ])->save();

    $export = app(PayrollBankPaymentExportBuilder::class)->build($run->refresh());

    expect($export)->toMatchArray([
        'filename' => 'payroll-bank-payment-placeholder-MY-2026-01-BANK-EXPORT.csv',
        'format' => 'csv',
        'status' => 'placeholder',
        'totals' => [
            'rows' => 1,
            'amount' => PAYSLIP_BASIC_SALARY_AMOUNT,
            'missing_bank_details' => 0,
        ],
    ])
        ->and($export['rows'][0])->toMatchArray([
            'export_status' => 'placeholder_not_bank_submittable',
            'payroll_run_code' => 'MY-2026-01-BANK-EXPORT',
            'bank_name' => TEST_BANK_NAME,
            'bank_account_number' => '1234567890',
            'amount' => PAYSLIP_BASIC_SALARY_AMOUNT,
            'currency' => 'MYR',
            'status' => 'ready_for_mapping',
        ])
        ->and($export['content'])->toContain('placeholder_not_bank_submittable')
        ->and($export['content'])->toContain(TEST_BANK_NAME);
});

test('malaysia country pack advertises bank payment placeholder export', function (): void {
    $definitions = app(MalaysiaPayrollCountryPack::class)->exports()->definitions();
    $bankExport = collect($definitions)->firstWhere('key', 'bank_payment_placeholder');

    expect($bankExport)->not()->toBeNull()
        ->and($bankExport->format)->toBe('csv')
        ->and($bankExport->metadata)->toMatchArray([
            'status' => 'placeholder',
            'not_bank_submittable' => true,
        ]);
});

test('operational payroll reports can be exported as csv', function (): void {
    [$run, $participant, $employee] = createPayrollCoreRun('MY-2026-01-CSV-REPORTS');

    foreach ([
        [PayrollResultLine::TYPE_EARNING, 'basic_salary', 'Basic Salary', BASIC_SALARY_AMOUNT, 'payroll-core-input-copy', 'v0'],
        [PayrollResultLine::TYPE_EMPLOYEE_CONTRIBUTION, 'my_epf_employee', EPF_EMPLOYEE_CONTRIBUTION_LABEL, EPF_EMPLOYEE_AMOUNT, 'epf_contribution_schedule', PAYROLL_DEV_VERSION],
        [PayrollResultLine::TYPE_EMPLOYER_CONTRIBUTION, 'my_epf_employer', EPF_EMPLOYER_CONTRIBUTION_LABEL, EPF_EMPLOYER_AMOUNT, 'epf_contribution_schedule', PAYROLL_DEV_VERSION],
        [PayrollResultLine::TYPE_NET_PAY, 'net_pay', NET_PAY_LABEL, STANDARD_NET_PAY_AMOUNT, 'payroll-core-neutral-net-pay', 'v0'],
    ] as [$type, $code, $label, $amount, $sourceRule, $sourceVersion]) {
        PayrollResultLine::query()->create([
            'payroll_run_id' => $run->id,
            'payroll_run_participant_id' => $participant->id,
            'employee_id' => $employee->id,
            'line_type' => $type,
            'code' => $code,
            'label' => $label,
            'amount' => $amount,
            'currency' => 'MYR',
            'source_rule' => $sourceRule,
            'source_version' => $sourceVersion,
        ]);
    }
    $participant->forceFill([
        'gross_pay' => BASIC_SALARY_AMOUNT,
        'total_deductions' => EPF_EMPLOYEE_AMOUNT,
        'net_pay' => STANDARD_NET_PAY_AMOUNT,
    ])->save();
    $run->markReviewed();

    $exports = app(PayrollOperationalCsvExportBuilder::class);
    $summary = $exports->payrollSummary($run->refresh());
    $statutory = $exports->statutoryContributions($run->refresh());
    $employerCost = $exports->employerCost($run->refresh());
    $lockAudit = $exports->lockAudit($run->refresh());

    expect($summary)->toMatchArray([
        'filename' => 'payroll-summary-MY-2026-01-CSV-REPORTS.csv',
        'format' => 'csv',
        'report_type' => 'payroll-summary',
        'headers' => ['payroll_run_code', 'period_code', 'employee_number', 'employee_name', 'gross_pay', 'employee_deductions', 'employee_contributions', 'taxes', 'reimbursements', 'net_pay'],
        'totals' => ['rows' => 1],
    ])
        ->and($summary['rows'][0])->toMatchArray([
            'payroll_run_code' => 'MY-2026-01-CSV-REPORTS',
            'gross_pay' => BASIC_SALARY_AMOUNT,
            'net_pay' => STANDARD_NET_PAY_AMOUNT,
        ])
        ->and($summary['content'])->toContain('gross_pay')
        ->and($summary['content'])->toContain(STANDARD_NET_PAY_AMOUNT)
        ->and($statutory['content'])->toContain('my_epf_employee')
        ->and($statutory['content'])->toContain('epf_contribution_schedule')
        ->and($employerCost['content'])->toContain('total_employer_cost')
        ->and($employerCost['content'])->toContain('3390.0000')
        ->and($lockAudit['content'])->toContain('reviewed')
        ->and($lockAudit['content'])->toContain('Payroll run reviewed.');
});

test('payroll pdf report factory builds inline render jobs with lineage metadata', function (): void {
    [$run, $participant, $employee] = createPayrollCoreRun('MY-2026-01-PDF-JOBS');

    PayrollResultLine::query()->create([
        'payroll_run_id' => $run->id,
        'payroll_run_participant_id' => $participant->id,
        'employee_id' => $employee->id,
        'line_type' => PayrollResultLine::TYPE_NET_PAY,
        'code' => 'net_pay',
        'label' => NET_PAY_LABEL,
        'amount' => BONUS_AMOUNT,
        'currency' => 'MYR',
    ]);
    $participant->forceFill(['net_pay' => BONUS_AMOUNT])->save();

    $factory = app(PayrollPdfReportJobFactory::class);
    $payslipJob = $factory->payslip($participant->refresh(), actorUserId: 7, password: 'secret');
    $summaryJob = $factory->payrollSummary($run->refresh(), actorUserId: 7);
    $statutoryJob = $factory->statutoryContributions($run->refresh(), actorUserId: 7);
    $employerCostJob = $factory->employerCost($run->refresh(), actorUserId: 7);
    $lockAuditJob = $factory->lockAudit($run->refresh(), actorUserId: 7);

    expect($payslipJob)
        ->view->toBe('pdf.payroll.payslip')
        ->templateVersion->toBe(PAYROLL_PAYSLIP_TEMPLATE_VERSION)
        ->actorUserId->toBe(7)
        ->password->toBe('secret')
        ->metadata->toMatchArray([
            'report_type' => 'payslip',
            'payroll_run_id' => $run->id,
            'payroll_run_participant_id' => $participant->id,
            'employee_id' => $employee->id,
        ])
        ->and($summaryJob)
        ->view->toBe('pdf.payroll.payroll-summary')
        ->templateVersion->toBe('payroll-summary@v1')
        ->metadata->toMatchArray(['report_type' => 'payroll_summary', 'payroll_run_id' => $run->id])
        ->and($statutoryJob)
        ->view->toBe('pdf.payroll.employee-statutory-contribution')
        ->templateVersion->toBe('employee-statutory-contribution@v1')
        ->metadata->toMatchArray(['report_type' => 'employee_statutory_contribution', 'payroll_run_id' => $run->id])
        ->and($employerCostJob)
        ->view->toBe('pdf.payroll.employer-cost')
        ->templateVersion->toBe('employer-cost@v1')
        ->metadata->toMatchArray(['report_type' => 'employer_cost', 'payroll_run_id' => $run->id])
        ->and($lockAuditJob)
        ->view->toBe('pdf.payroll.lock-audit')
        ->templateVersion->toBe('payroll-lock-audit@v1')
        ->metadata->toMatchArray(['report_type' => 'payroll_lock_audit', 'payroll_run_id' => $run->id]);
});

test('rendered payroll pdf artifacts are persisted from render events', function (): void {
    [$run, $participant, $employee] = createPayrollCoreRun('MY-2026-01-PDF-ARTIFACT');
    $participant->forceFill(['net_pay' => BONUS_AMOUNT])->save();
    $run->close();

    $job = app(PayrollPdfReportJobFactory::class)->payslip($participant->refresh());
    $artifact = new PdfArtifact(
        disk: 'local',
        path: PAYROLL_PAYSLIP_ARTIFACT_PATH,
        templateVersion: PAYROLL_PAYSLIP_TEMPLATE_VERSION,
        dataVersion: 'payroll_run_participant_id='.$participant->id,
        bytes: 12345,
        sha256: str_repeat('a', 64),
        producedBy: null,
        producedAt: new DateTimeImmutable('2026-01-31T12:00:00+00:00'),
    );

    event(new PdfArtifactRendered($job, $artifact));
    event(new PdfArtifactRendered($job, $artifact));

    $persisted = PayrollPdfArtifact::query()->orderBy('id')->firstOrFail();

    expect(PayrollPdfArtifact::query()->where('disk', 'local')->where('path', PAYROLL_PAYSLIP_ARTIFACT_PATH)->count())->toBe(2)
        ->and($persisted)->toMatchArray([
            'payroll_run_id' => $run->id,
            'payroll_run_participant_id' => $participant->id,
            'employee_id' => $employee->id,
            'report_type' => 'payslip',
            'disk' => 'local',
            'path' => PAYROLL_PAYSLIP_ARTIFACT_PATH,
            'template_version' => PAYROLL_PAYSLIP_TEMPLATE_VERSION,
            'data_version' => 'payroll_run_participant_id='.$participant->id,
            'bytes' => 12345,
            'sha256' => str_repeat('a', 64),
            'produced_by' => null,
        ])
        ->and($persisted->produced_at?->toIso8601String())->toBe('2026-01-31T12:00:00+00:00')
        ->and($persisted->metadata)->toMatchArray([
            'report_type' => 'payslip',
            'payroll_run_id' => $run->id,
            'payroll_run_participant_id' => $participant->id,
            'employee_id' => $employee->id,
        ])
        ->and($run->pdfArtifacts()->first()?->is($persisted))->toBeTrue()
        ->and($participant->pdfArtifacts()->first()?->is($persisted))->toBeTrue();
});

test('closed payroll runs cannot be recalculated', function (): void {
    [$run, $participant, $employee] = createPayrollCoreRun();
    PayrollInput::query()->create([
        'payroll_run_id' => $run->id,
        'payroll_run_participant_id' => $participant->id,
        'employee_id' => $employee->id,
        'pay_item_code' => 'basic_salary',
        'label' => 'Basic Salary',
        'input_type' => PayrollInput::TYPE_EARNING,
        'amount' => BASIC_SALARY_AMOUNT,
        'currency' => 'MYR',
    ]);

    $run->close();

    app(PayrollRunCalculator::class)->calculate($run);
})->throws(ClosedPayrollRunException::class);

test('closed payroll runs reject payroll detail mutations', function (): void {
    [$run, $participant, $employee] = createPayrollCoreRun();
    app(PayrollRunCalculator::class)->calculate($run);
    $run->close();

    PayrollInput::query()->create([
        'payroll_run_id' => $run->id,
        'payroll_run_participant_id' => $participant->id,
        'employee_id' => $employee->id,
        'pay_item_code' => 'basic_salary',
        'label' => 'Basic Salary',
        'input_type' => PayrollInput::TYPE_EARNING,
        'amount' => BASIC_SALARY_AMOUNT,
        'currency' => 'MYR',
    ]);
})->throws(ClosedPayrollRunException::class);

test('payroll core tables are registered for stability management', function (): void {
    foreach ([
        'people_payroll_calendars',
        'people_payroll_periods',
        'people_payroll_runs',
        'people_payroll_run_participants',
        'people_payroll_inputs',
        'people_payroll_result_lines',
        'people_payroll_run_audit_events',
        'people_payroll_pdf_artifacts',
        'people_payroll_pay_items',
        'people_payroll_pay_item_classifications',
        'people_payroll_employer_statutory_profiles',
        'people_payroll_employee_statutory_profiles',
        'people_payroll_statutory_rule_sets',
        'people_payroll_statutory_rule_rows',
    ] as $tableName) {
        $this->assertDatabaseHas('base_database_tables', [
            'table_name' => $tableName,
            'module_name' => 'Payroll',
        ]);
    }
});

test('pay item classifications resolve by country and effective date without country columns in core inputs', function (): void {
    $company = Company::query()->findOrFail(Company::LICENSEE_ID);
    $payItem = PayrollPayItem::query()->create([
        'company_id' => $company->id,
        'code' => 'basic_salary',
        'name' => 'Basic Salary',
        'input_type' => PayrollInput::TYPE_EARNING,
        'status' => 'active',
    ]);

    PayrollPayItemClassification::query()->create([
        'payroll_pay_item_id' => $payItem->id,
        'country_iso' => null,
        'classification_key' => 'payroll_input_family',
        'classification_value' => 'regular_earning',
        'effective_from' => PAYROLL_PERIOD_START,
        'source_pack' => 'payroll-core',
        'source_version' => 'v0',
    ]);
    PayrollPayItemClassification::query()->create([
        'payroll_pay_item_id' => $payItem->id,
        'country_iso' => 'MY',
        'classification_key' => 'statutory_wage_base',
        'classification_value' => 'ordinary_wage',
        'effective_from' => PAYROLL_PERIOD_START,
        'source_pack' => PAYROLL_MY_PACK,
        'source_version' => PAYROLL_COUNTRY_PACK_VERSION,
        'metadata' => ['reason' => 'Malaysia pack owns statutory treatment.'],
    ]);
    PayrollPayItemClassification::query()->create([
        'payroll_pay_item_id' => $payItem->id,
        'country_iso' => 'MY',
        'classification_key' => 'statutory_wage_base',
        'classification_value' => 'ordinary_wage_v2',
        'effective_from' => '2026-07-01',
        'source_pack' => PAYROLL_MY_PACK,
        'source_version' => '2026.2',
    ]);

    $january = app(PayItemClassifier::class)->classificationsFor($payItem, 'my', PAYROLL_PERIOD_END);
    $july = app(PayItemClassifier::class)->classificationsFor($payItem, 'MY', '2026-07-31');
    $singapore = app(PayItemClassifier::class)->classificationsFor($payItem, 'SG', '2026-07-31');

    expect($january)
        ->toHaveKey('payroll_input_family')
        ->toHaveKey('statutory_wage_base')
        ->and($january['payroll_input_family'])->toMatchArray([
            'value' => 'regular_earning',
            'country_iso' => null,
            'source_pack' => 'payroll-core',
        ])
        ->and($january['statutory_wage_base'])->toMatchArray([
            'value' => 'ordinary_wage',
            'country_iso' => 'MY',
            'source_pack' => PAYROLL_MY_PACK,
            'source_version' => PAYROLL_COUNTRY_PACK_VERSION,
            'metadata' => ['reason' => 'Malaysia pack owns statutory treatment.'],
        ])
        ->and($july['statutory_wage_base'])->toMatchArray([
            'value' => 'ordinary_wage_v2',
            'source_version' => '2026.2',
        ])
        ->and($singapore)->toHaveKey('payroll_input_family')
        ->and($singapore)->not()->toHaveKey('statutory_wage_base');
});

test('statutory profile resolver selects effective employer and employee profiles without Malaysia-specific columns', function (): void {
    $company = Company::query()->findOrFail(Company::LICENSEE_ID);
    $employee = Employee::factory()->create(['company_id' => $company->id]);

    PayrollEmployerStatutoryProfile::query()->create([
        'company_id' => $company->id,
        'country_iso' => 'MY',
        'source_pack' => PAYROLL_MY_PACK,
        'source_version' => PAYROLL_COUNTRY_PACK_VERSION,
        'effective_from' => PAYROLL_PERIOD_START,
        'effective_to' => '2026-06-30',
        'profile_data' => [
            'epf_employer_number' => 'EPF-OLD',
            'socso_employer_number' => 'SOCSO-001',
            'hrd_levy_applicable' => false,
        ],
        'validation_messages' => [],
    ]);
    PayrollEmployerStatutoryProfile::query()->create([
        'company_id' => $company->id,
        'country_iso' => 'MY',
        'source_pack' => PAYROLL_MY_PACK,
        'source_version' => '2026.2',
        'effective_from' => '2026-07-01',
        'profile_data' => [
            'epf_employer_number' => 'EPF-NEW',
            'socso_employer_number' => 'SOCSO-001',
            'hrd_levy_applicable' => true,
        ],
        'validation_messages' => [['level' => 'warning', 'message' => 'HRD levy requires monthly headcount confirmation.']],
    ]);
    PayrollEmployeeStatutoryProfile::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'country_iso' => 'MY',
        'source_pack' => PAYROLL_MY_PACK,
        'source_version' => PAYROLL_COUNTRY_PACK_VERSION,
        'effective_from' => PAYROLL_PERIOD_START,
        'profile_data' => [
            'citizenship_status' => 'citizen',
            'tax_residency' => 'resident',
            'epf_number' => 'KWSP-123',
            'zakat_salary_deduction_authorized' => false,
        ],
    ]);

    $resolver = app(StatutoryProfileResolver::class);
    $januaryEmployerProfile = $resolver->employerProfile($company, 'my', PAYROLL_PERIOD_END);
    $julyEmployerProfile = $resolver->employerProfile($company->id, 'MY', '2026-07-31');
    $employeeProfile = $resolver->employeeProfile($employee, 'MY', PAYROLL_PERIOD_END);

    expect($januaryEmployerProfile)
        ->not()->toBeNull()
        ->source_version->toBe(PAYROLL_COUNTRY_PACK_VERSION)
        ->and($januaryEmployerProfile->profile_data)->toMatchArray([
            'epf_employer_number' => 'EPF-OLD',
            'hrd_levy_applicable' => false,
        ])
        ->and($julyEmployerProfile)
        ->not()->toBeNull()
        ->source_version->toBe('2026.2')
        ->and($julyEmployerProfile->profile_data)->toMatchArray([
            'epf_employer_number' => 'EPF-NEW',
            'hrd_levy_applicable' => true,
        ])
        ->and($julyEmployerProfile->validation_messages)->toBe([
            ['level' => 'warning', 'message' => 'HRD levy requires monthly headcount confirmation.'],
        ])
        ->and($employeeProfile)
        ->not()->toBeNull()
        ->source_pack->toBe(PAYROLL_MY_PACK)
        ->and($employeeProfile->profile_data)->toMatchArray([
            'citizenship_status' => 'citizen',
            'tax_residency' => 'resident',
            'epf_number' => 'KWSP-123',
        ])
        ->and($resolver->employeeProfile($employee, 'SG', PAYROLL_PERIOD_END))->toBeNull();
});

test('statutory rule sets resolve effective contribution tables with ordered rows', function (): void {
    PayrollStatutoryRuleSet::query()->create([
        'country_iso' => 'MY',
        'rule_key' => 'epf_contribution_schedule',
        'name' => 'EPF contribution schedule 2026 H1',
        'source_pack' => PAYROLL_MY_PACK,
        'source_version' => PAYROLL_COUNTRY_PACK_VERSION,
        'effective_from' => PAYROLL_PERIOD_START,
        'effective_to' => '2026-06-30',
        'rounding_policy' => ['mode' => 'ceiling', 'precision' => '0.01'],
    ]);
    $secondHalfRuleSet = PayrollStatutoryRuleSet::query()->create([
        'country_iso' => 'MY',
        'rule_key' => 'epf_contribution_schedule',
        'name' => 'EPF contribution schedule 2026 H2',
        'source_pack' => PAYROLL_MY_PACK,
        'source_version' => '2026.2',
        'effective_from' => '2026-07-01',
        'rounding_policy' => ['mode' => 'ceiling', 'precision' => '0.01'],
        'metadata' => ['official_reference' => 'country-pack-maintained'],
    ]);
    PayrollStatutoryRuleRow::query()->create([
        'payroll_statutory_rule_set_id' => $secondHalfRuleSet->id,
        'sort_order' => 20,
        'row_key' => 'band-2',
        'min_wage' => '5000.0100',
        'max_wage' => null,
        'employee_rate' => EPF_EMPLOYEE_RATE,
        'employer_rate' => '0.12000000',
        'row_data' => ['category' => 'standard'],
    ]);
    PayrollStatutoryRuleRow::query()->create([
        'payroll_statutory_rule_set_id' => $secondHalfRuleSet->id,
        'sort_order' => 10,
        'row_key' => 'band-1',
        'min_wage' => ZERO_AMOUNT,
        'max_wage' => '5000.0000',
        'employee_rate' => EPF_EMPLOYEE_RATE,
        'employer_rate' => EPF_EMPLOYER_RATE,
        'row_data' => ['category' => 'standard'],
    ]);

    $ruleSet = app(StatutoryRuleSetResolver::class)->resolve('my', 'epf_contribution_schedule', '2026-07-31');

    expect($ruleSet)
        ->not()->toBeNull()
        ->source_pack->toBe(PAYROLL_MY_PACK)
        ->source_version->toBe('2026.2')
        ->and($ruleSet->rounding_policy)->toBe(['mode' => 'ceiling', 'precision' => '0.01'])
        ->and($ruleSet->metadata)->toBe(['official_reference' => 'country-pack-maintained'])
        ->and($ruleSet->rows)->toHaveCount(2)
        ->and($ruleSet->rows->pluck('row_key')->all())->toBe(['band-1', 'band-2'])
        ->and($ruleSet->rows->first()->max_wage)->toBe('5000.0000')
        ->and($ruleSet->rows->first()->employee_rate)->toBe(EPF_EMPLOYEE_RATE);

    expect(app(StatutoryRuleSetResolver::class)->resolve('MY', 'missing_rule', '2026-07-31'))->toBeNull();
});
