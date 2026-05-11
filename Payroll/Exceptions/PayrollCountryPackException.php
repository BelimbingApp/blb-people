<?php

namespace App\Modules\People\Payroll\Exceptions;

use App\Base\Foundation\Exceptions\BlbConfigurationException;

class PayrollCountryPackException extends BlbConfigurationException
{
    /**
     * @param  array<string, mixed>  $context
     */
    public static function unsupportedCoreContract(string $packIdentifier, string $coreContract, array $supportedContracts): self
    {
        return new self(
            "Payroll country pack [{$packIdentifier}] does not support Payroll Country Pack contract [{$coreContract}].",
            context: [
                'pack_identifier' => $packIdentifier,
                'core_contract' => $coreContract,
                'supported_contracts' => $supportedContracts,
            ],
        );
    }

    public static function duplicateCountry(string $countryIso, string $existingPack, string $newPack): self
    {
        return new self(
            "Payroll country [{$countryIso}] is already registered by pack [{$existingPack}].",
            context: [
                'country_iso' => $countryIso,
                'existing_pack' => $existingPack,
                'new_pack' => $newPack,
            ],
        );
    }

    public static function missingCountry(string $countryIso): self
    {
        return new self(
            "No payroll country pack is registered for [{$countryIso}].",
            context: ['country_iso' => $countryIso],
        );
    }
}
