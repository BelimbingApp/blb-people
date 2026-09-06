<?php

namespace App\Domains\People\Training\Data;

use App\Domains\People\Training\Enums\TrainingEventStatus;
use DateTimeInterface;

final readonly class TrainingPassportEvent
{
    public function __construct(
        public int $eventId,
        public string $title,
        public DateTimeInterface $startsAt,
        public DateTimeInterface $endsAt,
        public TrainingEventStatus $status,
        public bool $attended,
        public int $actualMinutes,
    ) {}
}
