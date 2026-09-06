<?php

namespace App\Domains\People\Training\Data;

use DateTimeInterface;

final readonly class TrainingPassportCertificate
{
    public string $statusLabel;

    public string $statusVariant;

    public function __construct(
        public int $eventId,
        public string $eventTitle,
        public string $reference,
        public ?DateTimeInterface $validFrom,
        public ?DateTimeInterface $validUntil,
        public bool $expired,
    ) {
        $this->statusLabel = $expired ? __('Expired') : __('Current');
        $this->statusVariant = $expired ? 'danger' : 'success';
    }
}
