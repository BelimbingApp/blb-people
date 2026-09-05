<?php

namespace App\Domains\People\Skills\Data;

use App\Domains\People\Skills\Enums\RequirementCriticality;

/**
 * One skill requirement as seen by assessment and gap consumers.
 *
 * Deliberately omits how the requirement was chosen (selectors, profile type,
 * tier). BelimbingApp/blb-people#80 / [0002-c].
 */
final readonly class ResolvedSkillRequirement
{
    public function __construct(
        public string $requirementReference,
        public int $requirementVersion,
        /**
         * The requirement profile version this requirement came from.
         *
         * Not a breach of the "omits how the requirement was chosen" rule
         * above: selectors, profile type and tier are still absent. This is
         * *what* applied, not how it was picked, and the versioning contract
         * requires an assessment to retain the particular version rather than a
         * code and a number that nothing guarantees still resolve.
         */
        public int $requirementProfileId,
        public int $skillId,
        public int $requiredLevel,
        public RequirementCriticality $criticality,
        public bool $mandatoryGate = false,
    ) {}

    /**
     * Workbook gap: how many proficiency steps short of the requirement.
     */
    public function gap(int $currentValidLevel): int
    {
        return max($this->requiredLevel - $currentValidLevel, 0);
    }
}
