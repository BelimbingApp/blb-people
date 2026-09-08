<?php

namespace App\Domains\People\Skills\Data;

use App\Domains\People\Skills\Import\WorkbookDefect;

/**
 * What an assessment-log apply did. With any defect nothing was written and
 * `created` is empty; otherwise `created` holds the new finalized assessment
 * ids in sheet order and `skipped` counts rows already present.
 */
final readonly class AssessmentLogImportResult
{
    /**
     * @param  list<int>  $created
     * @param  list<WorkbookDefect>  $defects
     */
    public function __construct(
        public string $sha256,
        public array $created,
        public int $skipped,
        public array $defects,
    ) {}
}
