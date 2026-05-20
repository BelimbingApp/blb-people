<?php

namespace App\Modules\People\Leave\Data;

use DateTimeInterface;

final readonly class LeaveLedgerEntryOptions
{
    /**
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public ?string $packIdentifier = null,
        public ?string $packVersion = null,
        public ?DateTimeInterface $occurredOn = null,
        public ?DateTimeInterface $expiresOn = null,
        public ?int $recordedByUserId = null,
        public ?string $note = null,
        public array $metadata = [],
    ) {}
}
