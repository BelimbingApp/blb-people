<?php

namespace App\Domains\People\Skills\Import;

/** One 04 Assessment Log row as source text; nothing here is resolved or validated. */
final readonly class AssessmentLogRow
{
    public function __construct(
        public string $assessmentId,
        public string $cycle,
        public string $assessedOn,
        public string $staffId,
        public string $skillId,
        public string $assessedLevel,
        public string $method,
        public string $evidence,
        public string $assessorStaffId,
        public string $hodVerified,
        public string $certificateNumber,
        public string $validUntil,
        public WorkbookSource $source,
    ) {}
}
