<?php

namespace App\Domains\People\Training\Data;

use DateTimeInterface;

final readonly class TrainingPlanDraft
{
    /** @param list<TrainingPlanItemDraft> $items */
    public function __construct(
        public int $departmentEntityId,
        public DateTimeInterface $periodStart,
        public DateTimeInterface $periodEnd,
        public string $objectives,
        public array $items,
        public bool $financialTrackingEnabled = false,
    ) {}
}
