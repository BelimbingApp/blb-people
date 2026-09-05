<?php

namespace App\Domains\People\Skills\Import;

final readonly class CatalogueCategoryRow
{
    public function __construct(
        public string $name,
        public WorkbookSource $source,
    ) {}
}
