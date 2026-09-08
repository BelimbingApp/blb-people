<?php

namespace App\Domains\People\Skills\Export;

/** The written workbook: row counts per contract sheet plus the file identity. */
final readonly class SkillWorkbookExportResult
{
    public function __construct(
        public int $skills,
        public int $levels,
        public string $sha256,
        public string $path,
    ) {}
}
