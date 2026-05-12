<?php

namespace App\Modules\People\Leave\Services;

use App\Modules\People\Leave\Contracts\LeaveCountryPack;
use App\Modules\People\Leave\Exceptions\LeaveCountryPackException;

class LeaveCountryPackRegistry
{
    public const CORE_CONTRACT_VERSION = 'leave-country-pack-v0';

    /** @var array<string, LeaveCountryPack> */
    private array $packsByCountry = [];

    public function register(LeaveCountryPack $pack): void
    {
        $manifest = $pack->manifest();
        $countryIso = $manifest->normalizedCountryIso();

        if (! $manifest->supportsCoreContract(self::CORE_CONTRACT_VERSION)) {
            throw LeaveCountryPackException::unsupportedCoreContract(
                $manifest->packIdentifier,
                self::CORE_CONTRACT_VERSION,
                $manifest->supportedCoreContracts,
            );
        }

        if (isset($this->packsByCountry[$countryIso])) {
            throw LeaveCountryPackException::duplicateCountry(
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

    public function forCountry(string $countryIso): LeaveCountryPack
    {
        $countryIso = strtoupper($countryIso);

        return $this->packsByCountry[$countryIso]
            ?? throw LeaveCountryPackException::missingCountry($countryIso);
    }

    /** @return list<string> */
    public function countries(): array
    {
        return array_keys($this->packsByCountry);
    }

    /** @return array<string, LeaveCountryPack> */
    public function all(): array
    {
        return $this->packsByCountry;
    }
}
