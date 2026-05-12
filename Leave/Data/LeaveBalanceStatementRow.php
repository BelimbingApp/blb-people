<?php

namespace App\Modules\People\Leave\Data;

class LeaveBalanceStatementRow
{
    /** @param array<string, float> $totalsByEntryType */
    public function __construct(
        public readonly int $leaveTypeId,
        public readonly string $leaveTypeCode,
        public readonly string $leaveTypeName,
        public readonly float $opening,
        public readonly float $accrued,
        public readonly float $taken,
        public readonly float $cancelled,
        public readonly float $adjusted,
        public readonly float $carriedForward,
        public readonly float $expired,
        public readonly float $encashed,
        public readonly float $balance,
        public readonly array $totalsByEntryType,
    ) {}
}
