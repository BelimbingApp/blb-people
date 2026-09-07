<?php

namespace App\Domains\People\Progression\Data;

use App\Domains\People\Progression\Enums\ProgressionRuleSource;
use App\Domains\People\Progression\Enums\ProgressionRuleStatus;

/**
 * One published rule, and what the evidence says about it.
 *
 * The requirement version travels with the answer so a reader can see which
 * version of the requirement the observation was taken against — a level of 3
 * means nothing without knowing what was being asked for at the time.
 */
final readonly class ProgressionRuleExplanation
{
    public function __construct(
        public string $code,
        public ProgressionRuleSource $source,
        public ProgressionRuleStatus $status,
        public ?int $requiredLevel = null,
        public ?int $observedLevel = null,
        public ?int $requirementVersion = null,
        /**
         * Which released review answered this rule, and which version of it.
         *
         * A performance answer without the review version behind it cannot be
         * audited later: the same period can have been reviewed once and then
         * corrected, and "met" means different things against each.
         */
        public ?int $reviewId = null,
        public ?int $reviewVersion = null,
        public ?string $period = null,
        /**
         * The consumed review supersedes an earlier one, so any decision taken
         * on the earlier answer needs governed reevaluation. This flags it; it
         * does not rewrite anything, and there is nothing here that could.
         */
        public bool $reevaluationRequired = false,
        public ?int $supersededReviewId = null,
    ) {}
}
