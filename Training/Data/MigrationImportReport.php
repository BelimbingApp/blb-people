<?php

namespace App\Domains\People\Training\Data;

/**
 * Outcome of one migration import or dry run (0015-e).
 *
 * Rejected rows carry reason codes only — never raw source field values.
 *
 * @phpstan-type RejectedRow array{row: int, reason: string}
 */
final readonly class MigrationImportReport
{
    /**
     * @param  list<int>  $importedRows  Source row numbers that were (or would be) applied
     * @param  list<int>  $skippedRows  Already present in the ledger
     * @param  list<RejectedRow>  $rejected
     */
    public function __construct(
        public string $sourceKey,
        public string $sourceSha256,
        public bool $dryRun,
        public int $read,
        public array $importedRows,
        public array $skippedRows,
        public array $rejected,
    ) {}

    public function importedCount(): int
    {
        return count($this->importedRows);
    }

    public function skippedCount(): int
    {
        return count($this->skippedRows);
    }

    public function rejectedCount(): int
    {
        return count($this->rejected);
    }
}
