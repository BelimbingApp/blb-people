<?php

namespace App\Modules\People\Attendance\Events;

use DateTimeImmutable;

/**
 * Producer-domain event: an attendance overtime request has been approved
 * and the producer wants downstream consumers (payroll plugins, audit
 * sinks) to learn about it.
 *
 * Payload carries only producer-domain facts. Pay-item codes, statutory
 * classification, and tax paths are payroll concepts the listener
 * resolves on its own side.
 */
final readonly class AttendanceOvertimeApproved
{
    public function __construct(
        public int $companyId,
        public int $employeeId,
        public int $overtimeRequestId,
        public DateTimeImmutable $occurredOn,
        public int $payableMinutes,
        public ?int $attendanceDayId = null,
    ) {}
}
