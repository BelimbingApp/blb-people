<?php

namespace App\Modules\People\Claim\Exceptions;

use RuntimeException;

class ClaimRequestLifecycleException extends RuntimeException
{
    public static function invalidStatus(int $requestId, string $status, string $action): self
    {
        return new self(sprintf(
            'Claim request %d in status [%s] cannot be %s.',
            $requestId,
            $status,
            $action,
        ));
    }

    public static function invalidSubmission(string $message): self
    {
        return new self($message);
    }
}
