<?php

namespace App\Domains\People\Organisation\Data;

final readonly class OrganisationIndicatorValue
{
    public function __construct(
        public ?int $value,
        public int $cohortSize,
        public bool $incomplete = false,
    ) {}
}
