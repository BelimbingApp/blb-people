<?php

namespace App\Domains\People\Performance\Data;

final readonly class JobDescriptionDraft
{
    /**
     * @param  list<string>  $responsibilities
     * @param  list<string>  $duties
     * @param  list<string>  $qualifications
     * @param  list<array{requirement_profile_id: int, requirement_profile_version: int}>  $competencyLinks
     */
    public function __construct(
        public string $reference,
        public string $positionStableId,
        public int $positionVersion,
        public int $version,
        public string $effectiveFrom,
        public ?string $effectiveTo,
        public string $purpose,
        public array $responsibilities,
        public array $duties,
        public string $authority,
        public array $qualifications,
        public array $competencyLinks,
    ) {}
}
