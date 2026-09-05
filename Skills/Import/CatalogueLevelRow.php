<?php

namespace App\Domains\People\Skills\Import;

final readonly class CatalogueLevelRow
{
    public function __construct(
        public string $level,
        public string $name,
        public string $observableStandard,
        public string $independentWork,
        public string $trainOthers,
        public string $normalDecision,
        public WorkbookSource $source,
    ) {}
}
