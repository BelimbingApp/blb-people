<?php

namespace App\Domains\People\Leave\Data;

final readonly class LeaveEncashmentOptions
{
    public function __construct(
        public ?int $actorUserId = null,
        public ?string $note = null,
        public ?string $currency = 'MYR',
    ) {}
}
