<?php

namespace App\Domains\People\Leave\Data;

final readonly class LeaveLedgerEntrySubject
{
    public function __construct(
        public int $companyId,
        public int $employeeId,
        public int $leaveTypeId,
        public int $leaveYear,
    ) {}
}
