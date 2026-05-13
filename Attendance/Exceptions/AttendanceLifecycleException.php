<?php

namespace App\Modules\People\Attendance\Exceptions;

use RuntimeException;

class AttendanceLifecycleException extends RuntimeException
{
    public static function lockedDay(int $attendanceDayId): self
    {
        return new self("Attendance day [{$attendanceDayId}] is locked.");
    }

    public static function notFinalizable(int $attendanceDayId, string $status): self
    {
        return new self("Attendance day [{$attendanceDayId}] cannot be finalized from status [{$status}].");
    }
}
