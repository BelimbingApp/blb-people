<?php

namespace App\Domains\People\Payroll\CountryPacks\Malaysia;

use App\Domains\People\Payroll\Contracts\CalculatesPayrollRun;
use App\Domains\People\Payroll\Contracts\ClassifiesPayrollPayItems;
use App\Domains\People\Payroll\Contracts\PayrollCountryPack;
use App\Domains\People\Payroll\Contracts\ProvidesPayrollExports;
use App\Domains\People\Payroll\Contracts\ProvidesPayrollProfileSchemas;
use App\Domains\People\Payroll\Data\CountryPackManifest;
use App\Domains\People\Payroll\Services\PayrollCountryPackRegistry;

class MalaysiaPayrollCountryPack implements PayrollCountryPack
{
    public const COUNTRY_ISO = 'MY';

    public const PACK_IDENTIFIER = 'belimbing/payroll-my';

    public const PACK_VERSION = '2026.dev';

    public function __construct(
        private readonly MalaysiaPayrollProfileSchemas $profileSchemas,
        private readonly MalaysiaPayItemClassifier $payItemClassifier,
        private readonly MalaysiaPayrollCalculator $calculator,
        private readonly MalaysiaPayrollExports $exports,
    ) {}

    public function manifest(): CountryPackManifest
    {
        return new CountryPackManifest(
            countryIso: self::COUNTRY_ISO,
            packIdentifier: self::PACK_IDENTIFIER,
            packVersion: self::PACK_VERSION,
            supportedCoreContracts: [PayrollCountryPackRegistry::CORE_CONTRACT_VERSION],
            statutoryDataVersions: ['2026.dev'],
            metadata: [
                'repository' => 'BelimbingApp/blb-payroll-my',
                'incubation' => 'internal-extension-shaped-pack',
            ],
        );
    }

    public function profileSchemas(): ProvidesPayrollProfileSchemas
    {
        return $this->profileSchemas;
    }

    public function payItemClassifier(): ClassifiesPayrollPayItems
    {
        return $this->payItemClassifier;
    }

    public function calculator(): CalculatesPayrollRun
    {
        return $this->calculator;
    }

    public function exports(): ProvidesPayrollExports
    {
        return $this->exports;
    }
}
