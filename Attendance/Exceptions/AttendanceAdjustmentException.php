<?php

namespace App\Modules\People\Attendance\Exceptions;

use RuntimeException;

class AttendanceAdjustmentException extends RuntimeException
{
    public static function invalidTransition(int $requestId, string $status, string $target): self
    {
        return new self("Adjustment request [{$requestId}] cannot transition from [{$status}] to [{$target}].");
    }

    public static function correctingEventMissing(int $requestId): self
    {
        return new self("Adjustment request [{$requestId}] is in correct_existing mode but no corrects_clock_event_id is set.");
    }
}
