<?php

namespace App\Domains\People\Skills\Events;

final readonly class RequirementProfileRetired
{
    public function __construct(
        public int $tenantId,
        public int $profileId,
        public string $code,
        public int $version,
    ) {}
}
