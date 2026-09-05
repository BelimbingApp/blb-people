<?php

namespace App\Domains\People\Skills\Data;

/** Explicit public projection: no private notes, decisions, or evidence links. */
final readonly class OwnAssessmentOutcome
{
    public function __construct(
        public int $assessmentId,
        public int $skillId,
        public string $requirementReference,
        public int $requirementVersion,
        public int $requiredLevel,
        public ?int $assessedLevel,
        public ?int $gap,
        public ?string $resultBand,
        public ?string $assessedAt,
        public string $finalizedAt,
        public ?string $validUntil,
        public ?string $nextAssessmentDue,
    ) {}
}
