<?php

namespace App\Domains\People\Training\Data;

use App\Domains\People\Training\Enums\PerformanceOutcomeUse;
use App\Domains\People\Training\Exceptions\InvalidEffectivenessPerformanceReferenceException;
use DateTimeInterface;

final readonly class EffectivenessPerformanceReference
{
    public function __construct(
        public string $reviewReference,
        public string $outcomeReference,
        public string $measure,
        public DateTimeInterface $periodStart,
        public DateTimeInterface $periodEnd,
        public string $source,
        public string $baseline,
        public string $permissionReference,
        public PerformanceOutcomeUse $use = PerformanceOutcomeUse::EvidenceOnly,
    ) {
        if (! $this->reference($reviewReference)
            || ! $this->reference($outcomeReference)
            || ! $this->reference($source)
            || ! $this->reference($permissionReference)
            || trim($measure) === ''
            || trim($baseline) === ''
            || $periodStart > $periodEnd) {
            throw new InvalidEffectivenessPerformanceReferenceException(
                'Effectiveness KPI evidence requires its review, outcome, measure, period, source, baseline, and permission context.',
            );
        }

        if ($use !== PerformanceOutcomeUse::EvidenceOnly) {
            throw new InvalidEffectivenessPerformanceReferenceException(
                'A KPI outcome is evidence only; it cannot prove training causation or change competence.',
            );
        }

    }

    private function reference(string $value): bool
    {
        return strlen($value) <= 160
            && preg_match('/^[a-zA-Z0-9][a-zA-Z0-9_.:-]*$/D', $value) === 1;
    }
}
