<?php

namespace App\Modules\People\Leave\Data;

class CarryForwardOutcome
{
    public function __construct(
        public readonly int $employeeId,
        public readonly int $leaveTypeId,
        public readonly int $fromYear,
        public readonly int $toYear,
        public readonly float $remainingBalance,
        public readonly float $capDays,
        public readonly float $carriedForward,
        public readonly float $expiredAtYearEnd,
        public readonly ?int $expiryMonth,
        public readonly string $anchor,
    ) {}
}
