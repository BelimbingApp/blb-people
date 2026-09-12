<?php

namespace App\Domains\People\Training\Data;

/**
 * One data row of an attendance-sheet CSV (0011-c): raw trimmed strings as
 * parsed, keyed by its 1-based row number. Interpretation (numbers, dates,
 * enums) belongs to the importer, so a malformed cell is a row defect with
 * a number, never a crash.
 */
final readonly class AttendanceSheetRow
{
    public function __construct(
        public int $row,
        public string $employeeNumber,
        public string $attendance,
        public string $actualMinutes,
        public string $preTestScore,
        public string $postTestScore,
        public string $certificateReference,
        public string $certificateValidUntil,
    ) {}
}
