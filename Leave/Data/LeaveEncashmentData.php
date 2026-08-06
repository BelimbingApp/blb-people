<?php

namespace App\Domains\People\Leave\Data;

final readonly class LeaveEncashmentData
{
    public function __construct(
        public int $companyId,
        public int $employeeId,
        public int $leaveTypeId,
        public int $leaveYear,
        public float $days,
        public ?LeaveEncashmentOptions $options = null,
    ) {}
}
