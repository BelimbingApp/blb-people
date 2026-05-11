<?php

namespace App\Modules\People\Payroll\Data;

class CountryPackManifest
{
    /**
     * @param  list<string>  $supportedCoreContracts
     * @param  list<string>  $statutoryDataVersions
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public readonly string $countryIso,
        public readonly string $packIdentifier,
        public readonly string $packVersion,
        public readonly array $supportedCoreContracts,
        public readonly array $statutoryDataVersions = [],
        public readonly array $metadata = [],
    ) {}

    public function normalizedCountryIso(): string
    {
        return strtoupper($this->countryIso);
    }

    public function supportsCoreContract(string $contractVersion): bool
    {
        return in_array($contractVersion, $this->supportedCoreContracts, true);
    }
}
