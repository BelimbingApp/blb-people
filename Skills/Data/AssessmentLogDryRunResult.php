<?php

namespace App\Domains\People\Skills\Data;

use App\Domains\People\Skills\Import\WorkbookDefect;

/** What an assessment-log import would do. Defects carry reason codes and cells, never cell values. */
final readonly class AssessmentLogDryRunResult
{
    /** @param  list<WorkbookDefect>  $defects */
    public function __construct(
        public string $sha256,
        public int $wouldCreate,
        public int $wouldSkip,
        public array $defects,
    ) {}
}
