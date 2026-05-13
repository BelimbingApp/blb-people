<?php

namespace App\Modules\People\Attendance\Exceptions;

use RuntimeException;

class AttendanceOvertimeException extends RuntimeException
{
    public static function invalidTransition(int $requestId, string $status, string $target): self
    {
        return new self("Overtime request [{$requestId}] cannot transition from [{$status}] to [{$target}].");
    }

    public static function missingPayItem(int $requestId): self
    {
        return new self("Overtime request [{$requestId}] has no payroll pay item mapping.");
    }
}
