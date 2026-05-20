<?php

namespace App\Modules\People\Leave\Data;

final readonly class LeaveLedgerEntrySource
{
    public function __construct(
        public string $type,
        public ?int $id = null,
    ) {}
}
