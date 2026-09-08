<?php

namespace App\Domains\People\Training\Data;

/** One field (and optionally one code) of a legacy source and where it lands in People (0015-b). */
final readonly class TrainingMigrationFieldMappingDraft
{
    public function __construct(
        public int $sourceId,
        public string $sourceField,
        public ?string $sourceCode,
        public string $targetTable,
        public string $targetColumn,
        public string $dedupRule,
    ) {}
}
