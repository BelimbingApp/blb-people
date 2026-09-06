<?php

namespace App\Domains\People\Training\Data;

final readonly class TrainingPassportSkill
{
    public function __construct(
        public int $skillId,
        public string $code,
        public string $name,
    ) {}
}
