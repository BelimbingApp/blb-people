<?php

namespace App\Modules\People\Leave\Data;

class LeaveBalanceStatement
{
    /**
     * @param  list<LeaveBalanceStatementRow>  $rows
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public readonly int $employeeId,
        public readonly int $leaveYear,
        public readonly array $rows,
        public readonly array $metadata = [],
    ) {}
}
