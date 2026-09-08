<?php

namespace App\Domains\People\Training\Data;

/**
 * The outcome of an attendance-sheet import (0011-c): how many rows were
 * recorded, how many were recognised from an earlier import of the same
 * file, and how many were refused with a per-row reason each.
 *
 * Validation runs before any write, so a refused row means nothing was
 * written: created and refused are never both non-zero.
 */
final readonly class AttendanceImportResult
{
    /** @param list<AttendanceImportDefect> $defects */
    public function __construct(
        public int $created,
        public int $skipped,
        public int $refused,
        public array $defects = [],
    ) {}

    /** @return array{created: int, skipped: int, refused: int, defects: list<array{row: int, message: string}>} */
    public function toArray(): array
    {
        return [
            'created' => $this->created,
            'skipped' => $this->skipped,
            'refused' => $this->refused,
            'defects' => array_map(static fn (AttendanceImportDefect $defect): array => $defect->toArray(), $this->defects),
        ];
    }
}
