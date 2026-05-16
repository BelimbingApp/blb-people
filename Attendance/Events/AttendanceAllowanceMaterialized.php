<?php

namespace App\Modules\People\Attendance\Events;

use DateTimeImmutable;

/**
 * Producer-domain event: an attendance allowance rule has materialized
 * against a specific attendance day, accruing the configured amount for
 * an employee.
 *
 * Payload carries only producer-domain facts. The listener looks up the
 * pay-item code for the rule via its own mapping table. (Phase 2 of plan
 * 12 moves that mapping to the payroll side; until then the listener
 * reads it directly off the AttendanceAllowanceRule row.)
 *
 * No producer dispatches this yet; Phase 3 of plan 12 wires the
 * materialization seam that fires it.
 */
final readonly class AttendanceAllowanceMaterialized
{
    public function __construct(
        public int $companyId,
        public int $employeeId,
        public int $attendanceAllowanceRuleId,
        public DateTimeImmutable $occurredOn,
        public float $amount,
        public ?int $attendanceDayId = null,
    ) {}
}
