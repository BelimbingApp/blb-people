<?php

namespace App\Modules\People\Payroll\Services;

use App\Modules\People\Payroll\Contracts\PayrollCountryPack;
use App\Modules\People\Payroll\Exceptions\PayrollCountryPackException;

class PayrollCountryPackRegistry
{
    public const CORE_CONTRACT_VERSION = 'payroll-country-pack-v0';

    /**
     * @var array<string, PayrollCountryPack>
     */
    private array $packsByCountry = [];

    public function register(PayrollCountryPack $pack): void
    {
        $manifest = $pack->manifest();
        $countryIso = $manifest->normalizedCountryIso();

        if (! $manifest->supportsCoreContract(self::CORE_CONTRACT_VERSION)) {
            throw PayrollCountryPackException::unsupportedCoreContract(
                $manifest->packIdentifier,
                self::CORE_CONTRACT_VERSION,
                $manifest->supportedCoreContracts,
            );
        }

        if (isset($this->packsByCountry[$countryIso])) {
            throw PayrollCountryPackException::duplicateCountry(
                $countryIso,
                $this->packsByCountry[$countryIso]->manifest()->packIdentifier,
                $manifest->packIdentifier,
            );
        }

        $this->packsByCountry[$countryIso] = $pack;
    }

    public function hasCountry(string $countryIso): bool
    {
        return isset($this->packsByCountry[strtoupper($countryIso)]);
    }

    public function forCountry(string $countryIso): PayrollCountryPack
    {
        $countryIso = strtoupper($countryIso);

        return $this->packsByCountry[$countryIso]
            ?? throw PayrollCountryPackException::missingCountry($countryIso);
    }

    /**
     * @return list<string>
     */
    public function countries(): array
    {
        return array_keys($this->packsByCountry);
    }

    /**
     * @return array<string, PayrollCountryPack>
     */
    public function all(): array
    {
        return $this->packsByCountry;
    }
}
