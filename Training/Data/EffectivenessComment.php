<?php

namespace App\Domains\People\Training\Data;

use App\Domains\People\Training\Enums\EffectivenessCheckpoint;

/** One HOD's written answer, attributed. */
final readonly class EffectivenessComment
{
    public function __construct(
        public EffectivenessCheckpoint $checkpoint,
        public int $rating,
        public string $comment,
        public string $answeredBy,
    ) {}
}
