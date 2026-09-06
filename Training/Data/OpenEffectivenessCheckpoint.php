<?php

namespace App\Domains\People\Training\Data;

use App\Domains\People\Training\Enums\EffectivenessCheckpoint;

/** One question waiting to be asked about one person's training. */
final readonly class OpenEffectivenessCheckpoint
{
    public function __construct(
        public int $participantId,
        public int $eventId,
        public int $employeeEntityId,
        public EffectivenessCheckpoint $checkpoint,
        /** Null when the participant's department has no head with an account. */
        public ?int $hodUserId,
        public bool $answered,
    ) {}
}
