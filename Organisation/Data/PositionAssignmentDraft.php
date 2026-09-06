<?php

namespace App\Domains\People\Organisation\Data;

use App\Domains\People\Organisation\Enums\PositionAssignmentType;
use DateTimeInterface;

final readonly class PositionAssignmentDraft
{
    public function __construct(
        public string $positionStableId,
        public int $employeeEntityId,
        public PositionAssignmentType $type,
        public DateTimeInterface $effectiveFrom,
        public ?DateTimeInterface $effectiveTo = null,
    ) {}
}
