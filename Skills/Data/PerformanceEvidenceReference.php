<?php

namespace App\Domains\People\Skills\Data;

use App\Domains\People\Skills\Enums\PerformanceCompetenceEffect;
use App\Domains\People\Skills\Exceptions\InvalidAssessmentException;
use App\Domains\People\Skills\Exceptions\PerformanceCannotDecideCompetenceException;

final readonly class PerformanceEvidenceReference
{
    public function __construct(
        public string $reference,
        public PerformanceCompetenceEffect $effect = PerformanceCompetenceEffect::EvidenceOnly,
    ) {
        if (strlen($reference) > 160
            || preg_match('/^[a-zA-Z0-9][a-zA-Z0-9_.:-]*$/D', $reference) !== 1) {
            throw new InvalidAssessmentException('Performance evidence requires an opaque governed-record reference.');
        }

        if ($effect !== PerformanceCompetenceEffect::EvidenceOnly) {
            throw new PerformanceCannotDecideCompetenceException(
                'Performance evidence may support an assessment but cannot set, satisfy, or revoke competence.',
            );
        }

    }
}
