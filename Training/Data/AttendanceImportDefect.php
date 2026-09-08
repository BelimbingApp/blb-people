<?php

namespace App\Domains\People\Training\Data;

/**
 * One refused sheet row: its 1-based number and why it was refused. The
 * message names the offending value so HR can fix the sheet, not guess.
 */
final readonly class AttendanceImportDefect
{
    public function __construct(
        public int $row,
        public string $message,
    ) {}

    /** @return array{row: int, message: string} */
    public function toArray(): array
    {
        return ['row' => $this->row, 'message' => $this->message];
    }
}
