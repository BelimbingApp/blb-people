<?php

namespace App\Modules\People\Leave\Data;

final readonly class LeaveLedgerEntryData
{
    public function __construct(
        public LeaveLedgerEntrySubject $subject,
        public string $entryType,
        public float $quantity,
        public string $unit,
        public LeaveLedgerEntrySource $source,
        public ?LeaveLedgerEntryPolicySnapshot $policy = null,
        public ?LeaveLedgerEntryOptions $options = null,
    ) {}
}
