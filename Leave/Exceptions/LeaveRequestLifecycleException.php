<?php

namespace App\Modules\People\Leave\Exceptions;

use App\Base\Foundation\Exceptions\BlbInvariantViolationException;

final class LeaveRequestLifecycleException extends BlbInvariantViolationException
{
    public static function invalidStatus(int $requestId, string $status, string $action): self
    {
        return new self(sprintf(
            'Leave request %d in status [%s] cannot be %s.',
            $requestId,
            $status,
            $action,
        ));
    }
}
