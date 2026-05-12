<?php

namespace App\Modules\People\Leave\Data;

use DateTimeImmutable;

class LeaveDayBreakdown
{
    public function __construct(
        public readonly DateTimeImmutable $occursOn,
        public readonly string $daytype,
        public readonly string $portion,
        public readonly ?float $hoursCount,
        public readonly bool $countsAgainstBalance,
        public readonly float $countedAsDays,
        public readonly ?string $daytypeLabel = null,
        public readonly ?string $note = null,
    ) {}
}
