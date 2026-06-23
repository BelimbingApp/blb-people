<?php

use App\Modules\People\Payroll\Contracts\CalculatesPayrollRun;
use App\Modules\People\Payroll\Contracts\ClassifiesPayrollPayItems;
use App\Modules\People\Payroll\Contracts\PayrollCountryPack;
use App\Modules\People\Payroll\Contracts\ProvidesPayrollExports;
use App\Modules\People\Payroll\Contracts\ProvidesPayrollProfileSchemas;
use App\Modules\People\Payroll\Data\CountryPackManifest;
use App\Modules\People\Payroll\Data\PayrollCalculationContext;
use App\Modules\People\Payroll\Data\PayrollCalculationResult;
use App\Modules\People\Payroll\Data\PayrollExportDefinition;
use App\Modules\People\Payroll\Data\PayrollProposedResultLine;
use App\Modules\People\Payroll\Data\ProfileSchema;
use App\Modules\People\Payroll\Exceptions\PayrollCountryPackException;
use App\Modules\People\Payroll\Models\PayrollPayItem;
use App\Modules\People\Payroll\Models\PayrollResultLine;
use App\Modules\People\Payroll\Services\PayrollCountryPackRegistry;
use Illuminate\Support\Carbon;

defined('PAYROLL_MY_PACK') || define('PAYROLL_MY_PACK', 'belimbing/payroll-my');
defined('PAYROLL_DEV_VERSION') || define('PAYROLL_DEV_VERSION', '2026.dev');

function createPayrollContractTestPack(
    string $countryIso = 'MY',
    string $packIdentifier = PAYROLL_MY_PACK,
    array $supportedContracts = [PayrollCountryPackRegistry::CORE_CONTRACT_VERSION],
): PayrollCountryPack {
    return new class($countryIso, $packIdentifier, $supportedContracts) implements CalculatesPayrollRun, ClassifiesPayrollPayItems, PayrollCountryPack, ProvidesPayrollExports, ProvidesPayrollProfileSchemas
    {
        public function __construct(
            private readonly string $countryIso,
            private readonly string $packIdentifier,
            private readonly array $supportedContracts,
        ) {}

        public function manifest(): CountryPackManifest
        {
            return new CountryPackManifest(
                countryIso: $this->countryIso,
                packIdentifier: $this->packIdentifier,
                packVersion: PAYROLL_DEV_VERSION,
                supportedCoreContracts: $this->supportedContracts,
                statutoryDataVersions: [PAYROLL_DEV_VERSION],
            );
        }

        public function profileSchemas(): ProvidesPayrollProfileSchemas
        {
            return $this;
        }

        public function employerSchema(): ProfileSchema
        {
            return new ProfileSchema(
                countryIso: $this->countryIso,
                profileType: 'employer',
                sourcePack: $this->packIdentifier,
                sourceVersion: PAYROLL_DEV_VERSION,
                fields: [
                    ['key' => 'epf_employer_number', 'label' => 'EPF employer number', 'required' => true],
                ],
            );
        }

        public function employeeSchema(): ProfileSchema
        {
            return new ProfileSchema(
                countryIso: $this->countryIso,
                profileType: 'employee',
                sourcePack: $this->packIdentifier,
                sourceVersion: PAYROLL_DEV_VERSION,
                fields: [
                    ['key' => 'epf_number', 'label' => 'EPF number', 'required' => false],
                ],
            );
        }

        public function payItemClassifier(): ClassifiesPayrollPayItems
        {
            return $this;
        }

        public function classificationsFor(PayrollPayItem $payItem, Carbon|string $onDate): array
        {
            return [
                'statutory_wage_base' => [
                    'value' => 'ordinary_wage',
                    'source_pack' => $this->packIdentifier,
                    'source_version' => PAYROLL_DEV_VERSION,
                ],
            ];
        }

        public function calculator(): CalculatesPayrollRun
        {
            return $this;
        }

        public function calculate(PayrollCalculationContext $context): PayrollCalculationResult
        {
            return new PayrollCalculationResult(resultLines: [
                new PayrollProposedResultLine(
                    lineType: PayrollResultLine::TYPE_EMPLOYEE_CONTRIBUTION,
                    code: 'epf_employee',
                    label: 'EPF Employee',
                    amount: '330.0000',
                    currency: 'MYR',
                    sourceRule: 'epf_contribution_schedule',
                    sourceVersion: PAYROLL_DEV_VERSION,
                    explanation: ['country_iso' => $this->countryIso],
                ),
            ]);
        }

        public function exports(): ProvidesPayrollExports
        {
            return $this;
        }

        public function definitions(): array
        {
            return [
                new PayrollExportDefinition(
                    key: 'epf_monthly_contribution',
                    label: 'EPF monthly contribution',
                    frequency: 'monthly',
                    format: 'csv',
                ),
            ];
        }
    };
}

test('payroll country pack registry exposes the v0 extension contract facets', function (): void {
    $registry = new PayrollCountryPackRegistry;
    $registry->register(createPayrollContractTestPack(countryIso: 'my'));

    $pack = $registry->forCountry('MY');

    expect($registry->hasCountry('my'))->toBeTrue()
        ->and($registry->countries())->toBe(['MY'])
        ->and($pack->manifest())
        ->packIdentifier->toBe(PAYROLL_MY_PACK)
        ->and($pack->manifest()->supportsCoreContract(PayrollCountryPackRegistry::CORE_CONTRACT_VERSION))->toBeTrue()
        ->and($pack->profileSchemas()->employerSchema()->fields[0]['key'])->toBe('epf_employer_number')
        ->and($pack->profileSchemas()->employeeSchema()->fields[0]['key'])->toBe('epf_number')
        ->and($pack->payItemClassifier()->classificationsFor(new PayrollPayItem, '2026-01-31')['statutory_wage_base']['value'])->toBe('ordinary_wage')
        ->and($pack->exports()->definitions()[0]->key)->toBe('epf_monthly_contribution');
});

test('payroll country pack registry rejects incompatible or duplicate packs', function (): void {
    $registry = new PayrollCountryPackRegistry;

    $registry->register(createPayrollContractTestPack(packIdentifier: PAYROLL_MY_PACK));

    expect(fn () => $registry->register(createPayrollContractTestPack(packIdentifier: 'other/payroll-my')))
        ->toThrow(PayrollCountryPackException::class, 'already registered')
        ->and(fn () => (new PayrollCountryPackRegistry)->register(createPayrollContractTestPack(supportedContracts: ['legacy-contract'])))
        ->toThrow(PayrollCountryPackException::class, 'does not support')
        ->and(fn () => $registry->forCountry('SG'))
        ->toThrow(PayrollCountryPackException::class, 'No payroll country pack');
});

test('payroll country pack registry is a singleton service for extension registration', function (): void {
    $first = app(PayrollCountryPackRegistry::class);
    $second = app(PayrollCountryPackRegistry::class);

    expect($first)->toBe($second);
});

test('resolves distinct country packs per country in one deployment', function (): void {
    // The adapter model's core promise: one deployment can serve companies in
    // different countries at once (which a one-variant-per-deployment slot
    // cannot). Each country resolves its own pack independently.
    $registry = new PayrollCountryPackRegistry;
    $registry->register(createPayrollContractTestPack(countryIso: 'MY', packIdentifier: 'belimbing/payroll-my'));
    $registry->register(createPayrollContractTestPack(countryIso: 'SG', packIdentifier: 'belimbing/payroll-sg'));

    expect($registry->countries())->toHaveCount(2)->toContain('MY')->toContain('SG')
        ->and($registry->forCountry('MY')->manifest()->packIdentifier)->toBe('belimbing/payroll-my')
        ->and($registry->forCountry('SG')->manifest()->packIdentifier)->toBe('belimbing/payroll-sg');
});

test('malaysia payroll country pack is registered with profile schemas and planned exports', function (): void {
    $pack = app(PayrollCountryPackRegistry::class)->forCountry('my');
    $manifest = $pack->manifest();

    expect($manifest->packIdentifier)->toBe(PAYROLL_MY_PACK)
        ->and($manifest->metadata['repository'])->toBe('BelimbingApp/blb-payroll-my')
        ->and($pack->profileSchemas()->employerSchema()->fields)
        ->toContain(['key' => 'epf_employer_number', 'label' => 'EPF employer number', 'required' => true])
        ->and($pack->profileSchemas()->employeeSchema()->fields)
        ->toContain(['key' => 'citizenship_status', 'label' => 'Citizenship status', 'required' => true])
        ->and(collect($pack->exports()->definitions())->pluck('key')->all())
        ->toBe([
            'epf_monthly_contribution',
            'socso_eis_monthly_contribution',
            'pcb_cp39_monthly_submission',
            'bank_payment_placeholder',
        ]);
});
