<?php

namespace App\Domains\People\Skills\Import;

final readonly class WorkbookDefect
{
    public function __construct(
        public string $kind,
        public string $cell,
        public WorkbookSource $source,
    ) {}
}
