<?php

namespace App\Domains\People\Training\Data;

use App\Domains\People\Training\Exceptions\InvalidTrainingParticipationException;

/**
 * An attendance sheet parsed from CSV (0011-c): the sha256 of the file bytes
 * plus its data rows. The hash is the idempotency key: a source reference of
 * `<hash>:<row>` names the exact cell a fact came from, so a second import
 * of the same file is recognised row by row instead of duplicated.
 *
 * Parsing is tolerant of extra columns — the attendance export carries more
 * columns than the import needs, and the file round-trips — but every
 * required column must be present in the header.
 */
final readonly class AttendanceSheet
{
    public const COLUMNS = [
        'employee_number',
        'attendance',
        'actual_minutes',
        'pre_test_score',
        'post_test_score',
        'certificate_reference',
        'certificate_valid_until',
    ];

    /** @param list<AttendanceSheetRow> $rows */
    public function __construct(
        public string $fileHash,
        public array $rows = [],
    ) {}

    public static function fromCsv(string $contents): self
    {
        $handle = fopen('php://temp', 'r+');
        if ($handle === false) {
            throw new InvalidTrainingParticipationException('The attendance sheet could not be read.');
        }

        fwrite($handle, $contents);
        rewind($handle);

        $headers = null;
        $rows = [];
        while (($record = fgetcsv($handle)) !== false) {
            if ($headers === null) {
                $headers = array_map(
                    // A byte-order mark rides on the first header of
                    // spreadsheet-saved files; strip it so the column still
                    // matches instead of failing the whole sheet.
                    static fn (?string $header): string => trim((string) $header, " \t\n\r\0\x0B\u{FEFF}"),
                    $record,
                );
                $missing = array_diff(self::COLUMNS, $headers);
                if ($missing !== []) {
                    fclose($handle);

                    throw new InvalidTrainingParticipationException(
                        'Expected the columns '.implode(', ', self::COLUMNS).'.',
                    );
                }

                continue;
            }

            if ($record === [null] || $record === []) {
                continue;
            }

            $cells = [];
            foreach ($record as $index => $cell) {
                $cells[$headers[$index] ?? ''] = trim((string) $cell);
            }
            $rows[] = new AttendanceSheetRow(
                row: count($rows) + 1,
                employeeNumber: $cells['employee_number'] ?? '',
                attendance: $cells['attendance'] ?? '',
                actualMinutes: $cells['actual_minutes'] ?? '',
                preTestScore: $cells['pre_test_score'] ?? '',
                postTestScore: $cells['post_test_score'] ?? '',
                certificateReference: $cells['certificate_reference'] ?? '',
                certificateValidUntil: $cells['certificate_valid_until'] ?? '',
            );
        }

        fclose($handle);

        return new self(hash('sha256', $contents), $rows);
    }
}
