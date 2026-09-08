<?php

namespace App\Domains\People\Training\Data;

use App\Domains\People\Training\Enums\MigrationSourceKind;

/** What HR types in about one legacy source (0015-a). */
final readonly class TrainingMigrationSourceDraft
{
    public function __construct(
        public string $sourceKey,
        public string $name,
        public MigrationSourceKind $kind,
        public string $format,
        public ?int $ownerEmployeeEntityId = null,
        public ?int $estimatedVolume = null,
        public ?string $retentionNote = null,
        public ?string $dataQualityNote = null,
    ) {}
}
