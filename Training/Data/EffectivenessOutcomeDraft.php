<?php

namespace App\Domains\People\Training\Data;

use App\Domains\People\Training\Enums\EffectivenessOutcome;
use DateTimeInterface;

/**
 * The three workplace observations stay three distinct 1-5 ratings. The
 * contract forbids averaging them into a competence score, so nothing here
 * derives a level from them.
 */
final readonly class EffectivenessOutcomeDraft
{
    public function __construct(
        public EffectivenessOutcome $outcome,
        public int $applicationRating,
        public int $improvementRating,
        public int $impactRating,
        public string $evidence,
        public DateTimeInterface $reviewedOn,
        public ?string $furtherAction = null,
    ) {}
}
