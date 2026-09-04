<?php

namespace App\Domains\People\Provider\Data;

use DateTimeImmutable;

/**
 * Pagination state for one incremental read: the tenant, the instant the
 * read replays from, the instant it was started (its as-of, and the next
 * resume point), and the employee-id window it walks. Pagination state, never
 * an authorization source — the codec binds it to the tenant that minted it.
 */
final readonly class WorkforceChangeCursor
{
    public function __construct(
        public int $tenantId,
        public DateTimeImmutable $since,
        public DateTimeImmutable $startedAt,
        public int $afterEmployeeId,
        public int $throughEmployeeId,
    ) {
        if ($tenantId < 1 || $afterEmployeeId < 0 || $throughEmployeeId < $afterEmployeeId) {
            throw new \InvalidArgumentException('Workforce change cursors require a valid tenant and employee range.');
        }
        if ($since > $startedAt) {
            throw new \InvalidArgumentException('A workforce change cursor cannot replay from after its own start.');
        }
    }
}
