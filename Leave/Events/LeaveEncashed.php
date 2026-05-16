<?php

namespace App\Modules\People\Leave\Events;

use DateTimeImmutable;

/**
 * Producer-domain event: a leave balance was encashed. Carries the
 * facts a downstream consumer needs to compute the payout — type id,
 * ledger entry id, year, days, currency.
 */
final readonly class LeaveEncashed
{
    public function __construct(
        public int $companyId,
        public int $employeeId,
        public int $leaveTypeId,
        public int $leaveBalanceLedgerEntryId,
        public int $leaveYear,
        public DateTimeImmutable $occurredOn,
        public float $days,
        public string $currency,
    ) {}
}
