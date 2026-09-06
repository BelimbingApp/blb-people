<?php

namespace App\Domains\People\Employees\Data;

/** Explicit public projection: outcome state and date only — no reviewer material. */
final readonly class OwnEffectivenessOutcome
{
    public function __construct(
        public string $stage,
        public string $state,
        public ?string $outcome,
        public ?string $reviewedOn,
        public ?string $outcomeRecordedAt,
    ) {}
}
