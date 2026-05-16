<?php

namespace App\Modules\People\Leave\Events;

use DateTimeImmutable;

/**
 * Producer-domain event: a leave request was applied and the producer
 * wants downstream consumers to learn about it. The listener decides
 * whether the leave type interacts with payroll and what to do.
 */
final readonly class LeaveApplied
{
    public function __construct(
        public int $companyId,
        public int $employeeId,
        public int $leaveRequestId,
        public int $leaveTypeId,
        public int $leaveBalanceLedgerEntryId,
        public DateTimeImmutable $occurredOn,
        public float $quantity,
        public string $unit,
    ) {}
}
