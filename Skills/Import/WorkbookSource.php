<?php

namespace App\Domains\People\Skills\Import;

final readonly class WorkbookSource
{
    public function __construct(
        public string $sha256,
        public string $sheet,
        public int $row,
    ) {}
}
