<?php

namespace App\Domains\People\Skills\Events;

final readonly class SkillDeactivated
{
    public function __construct(
        public int $tenantId,
        public int $skillId,
        public string $code,
    ) {}
}
