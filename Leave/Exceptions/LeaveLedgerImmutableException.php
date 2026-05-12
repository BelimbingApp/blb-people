<?php

namespace App\Modules\People\Leave\Exceptions;

use RuntimeException;

class LeaveLedgerImmutableException extends RuntimeException
{
    public static function cannotUpdate(): self
    {
        return new self('Leave balance ledger entries are append-only; existing rows cannot be updated.');
    }

    public static function cannotDelete(): self
    {
        return new self('Leave balance ledger entries are append-only; existing rows cannot be deleted. Record a reversing entry instead.');
    }
}
