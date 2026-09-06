<?php

namespace App\Domains\People\Training\Data;

use App\Domains\People\Training\Enums\EffectivenessReviewStage;
use DateTimeInterface;

/**
 * Opening one effectiveness review occurrence.
 *
 * The due date arrives with the policy that chose it. The contract refuses to
 * let the code silently anchor on course start, completion or return to work,
 * so the anchor is recorded as the caller's stated policy rather than inferred.
 * Baseline and target stay nullable: unverified evidence is unknown, not zero.
 */
final readonly class EffectivenessReviewDraft
{
    public function __construct(
        public int $participantId,
        public EffectivenessReviewStage $stage,
        public DateTimeInterface $dueOn,
        public string $dueDatePolicy,
        public int $reviewerEmployeeEntityId,
        public ?int $baselineLevel = null,
        public ?int $targetLevel = null,
        public ?string $requirementReference = null,
        public ?int $requirementVersion = null,
    ) {}
}
