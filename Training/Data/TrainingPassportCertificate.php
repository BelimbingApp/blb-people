<?php

namespace App\Domains\People\Training\Data;

use DateTimeInterface;

final readonly class TrainingPassportCertificate
{
    public function __construct(
        public int $eventId,
        public string $eventTitle,
        public string $reference,
        public ?DateTimeInterface $validFrom,
        public ?DateTimeInterface $validUntil,
        public bool $expired,
    ) {}
}
