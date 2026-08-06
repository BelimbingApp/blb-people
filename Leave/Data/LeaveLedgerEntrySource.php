<?php

namespace App\Domains\People\Leave\Data;

final readonly class LeaveLedgerEntrySource
{
    public function __construct(
        public string $type,
        public ?int $id = null,
    ) {}
}
