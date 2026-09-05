<?php

namespace App\Domains\People\Performance\Data;

final readonly class JobDescriptionDraft
{
    /** @param list<string> $responsibilities */
    public function __construct(
        public string $reference,
        public string $positionStableId,
        public int $version,
        public string $effectiveFrom,
        public ?string $effectiveTo,
        public array $responsibilities,
        public int $requirementProfileId,
        public int $requirementProfileVersion,
    ) {}
}
