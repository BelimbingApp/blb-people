<?php

namespace App\Domains\People\Skills\Data;

/**
 * One defect-free 04 Assessment Log row as the dry run resolved it: workforce
 * and catalogue ids, not workbook text. `wouldSkip` is true when a finalized
 * assessment for the same employee, skill and date already exists.
 */
final readonly class AssessmentLogPlannedRow
{
    public function __construct(
        public int $row,
        public int $employeeEntityId,
        public int $skillId,
        public int $assessedLevel,
        public string $assessedOn,
        public ?string $validUntil,
        public string $method,
        public string $cycle,
        public string $evidence,
        public string $assessorStaffId,
        public string $hodVerified,
        public ?string $certificateNumber,
        public bool $wouldSkip,
    ) {}
}
