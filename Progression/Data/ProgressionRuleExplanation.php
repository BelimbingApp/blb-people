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
    ) {}
}
