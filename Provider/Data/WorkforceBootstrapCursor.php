<?php

namespace App\Domains\People\Provider\Data;

use DateTimeImmutable;

final readonly class WorkforceBootstrapCursor
{
    public function __construct(
        public int $tenantId,
        public int $afterEmployeeId,
        public int $throughEmployeeId,
        public DateTimeImmutable $startedAt,
    ) {
        if ($tenantId < 1 || $afterEmployeeId < 0 || $throughEmployeeId < $afterEmployeeId) {
            throw new \InvalidArgumentException('Workforce bootstrap cursors require a valid tenant and employee range.');
        }
    }
}
