<?php

namespace App\Modules\People\Attendance\Exceptions;

use RuntimeException;

class AttendanceClockEventIngestionException extends RuntimeException
{
    public static function invalidEventType(string $eventType): self
    {
        return new self("Unsupported attendance clock event type [{$eventType}].");
    }

    public static function lockedAttendanceDay(int $attendanceDayId): self
    {
        return new self("Attendance day [{$attendanceDayId}] is locked and cannot accept new clock events.");
    }

    public static function correctedEventCompanyMismatch(int $clockEventId): self
    {
        return new self("Corrected clock event [{$clockEventId}] does not belong to the target company.");
    }
}
