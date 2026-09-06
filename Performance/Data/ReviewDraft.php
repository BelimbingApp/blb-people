<?php

namespace App\Domains\People\Performance\Data;

use App\Domains\People\Performance\Enums\PerformanceOutcome;
use DateTimeInterface;

/**
 * One period review. The observation ids are pinned at finalization, so a
 * later correction to an observation cannot change what a released review was
 * based on.
 */
final readonly class ReviewDraft
{
    /** @param list<int> $observationIds */
    public function __construct(
        public int $employeeEntityId,
        public DateTimeInterface $periodStart,
        public DateTimeInterface $periodEnd,
        public DateTimeInterface $cutoffAt,
        public array $observationIds,
        public PerformanceOutcome $outcome,
        public string $rationale,
    ) {}
}
