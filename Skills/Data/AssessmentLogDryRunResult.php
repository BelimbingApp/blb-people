<?php

namespace App\Domains\People\Skills\Data;

use App\Domains\People\Skills\Import\WorkbookDefect;

/**
 * What an assessment-log import would do. Defects carry reason codes and cells,
 * never cell values. `plan` lists the defect-free rows in sheet order so the
 * importer applies exactly what the dry run counted.
 */
final readonly class AssessmentLogDryRunResult
{
    /**
     * @param  list<WorkbookDefect>  $defects
     * @param  list<AssessmentLogPlannedRow>  $plan
     */
    public function __construct(
        public string $sha256,
        public int $wouldCreate,
        public int $wouldSkip,
        public array $defects,
        public array $plan = [],
    ) {}
}
