<?php

namespace App\Domains\People\Training\Data;

use App\Domains\People\Training\Enums\DeliveryMode;
use DateTimeInterface;

final readonly class TrainingEventDraft
{
    public function __construct(
        public int $courseId,
        public DateTimeInterface $startsAt,
        public DateTimeInterface $endsAt,
        public int $capacity,
        public int $organizerEmployeeEntityId,
        public ?int $targetDepartmentEntityId = null,
        public ?DeliveryMode $deliveryMode = null,
        public ?string $venue = null,
        public ?int $internalTrainerEmployeeEntityId = null,
        public ?string $externalTrainerReference = null,
        public ?string $externalTrainerName = null,
    ) {}
}
