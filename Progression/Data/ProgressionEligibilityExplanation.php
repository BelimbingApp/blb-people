<?php

namespace App\Domains\People\Progression\Data;

/**
 * What the published policy asks of one person, and what the evidence says.
 *
 * Deliberately has no eligible() and no score. Every rule reading met is still
 * not a decision: awarding progression is somebody's judgement, made with
 * things this service cannot see, and the cheapest way to keep that true is to
 * give this record nowhere to put a verdict.
 */
final readonly class ProgressionEligibilityExplanation
{
    /** @param list<ProgressionRuleExplanation> $rules */
    public function __construct(
        public int $tenantId,
        public int $companyId,
        public string $policyId,
        public string $policyVersion,
        public array $rules,
    ) {}
}
