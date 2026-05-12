<?php

namespace App\Modules\People\Leave\Exceptions;

use RuntimeException;

class LeaveCountryPackException extends RuntimeException
{
    /** @param list<string> $supportedByPack */
    public static function unsupportedCoreContract(string $packIdentifier, string $expectedContract, array $supportedByPack): self
    {
        return new self(sprintf(
            'Leave country pack [%s] does not support core contract [%s]. Pack supports: %s.',
            $packIdentifier,
            $expectedContract,
            implode(', ', $supportedByPack) ?: '(none)',
        ));
    }

    public static function duplicateCountry(string $countryIso, string $existingPack, string $incomingPack): self
    {
        return new self(sprintf(
            'Leave country pack for country [%s] is already registered as [%s]; refusing to register [%s].',
            $countryIso,
            $existingPack,
            $incomingPack,
        ));
    }

    public static function missingCountry(string $countryIso): self
    {
        return new self(sprintf('No leave country pack registered for country [%s].', $countryIso));
    }
}
