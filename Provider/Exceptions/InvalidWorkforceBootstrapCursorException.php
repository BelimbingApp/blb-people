<?php

namespace App\Domains\People\Provider\Exceptions;

use App\Base\Foundation\Exceptions\BlbDataContractException;
use Throwable;

final class InvalidWorkforceBootstrapCursorException extends BlbDataContractException
{
    public static function malformed(?Throwable $previous = null): self
    {
        return new self('The workforce bootstrap cursor is invalid.', previous: $previous);
    }

    public static function forDifferentTenant(): self
    {
        return new self('The workforce bootstrap cursor does not belong to the current tenant.');
    }
}
