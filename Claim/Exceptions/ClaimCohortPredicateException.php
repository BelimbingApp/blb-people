<?php

namespace App\Modules\People\Claim\Exceptions;

use RuntimeException;

class ClaimCohortPredicateException extends RuntimeException
{
    /** @param  list<string>  $allowed */
    public static function unknownKey(string $key, array $allowed): self
    {
        return new self(sprintf(
            'Cohort predicate key [%s] is not allowed. Allowed keys: %s.',
            $key,
            implode(', ', $allowed),
        ));
    }
}
