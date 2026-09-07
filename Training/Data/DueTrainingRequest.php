<?php

namespace App\Domains\People\Training\Data;

use Carbon\CarbonImmutable;

/** One approved training request that has waited for an event longer than the configured age. */
final readonly class DueTrainingRequest
{
    public function __construct(
        public int $requestId,
        public string $need,
        /** The calendar day the approval decision was recorded. */
        public CarbonImmutable $approvedOn,
        /** Whole days from the approval day to the run day. */
        public int $daysUnlinked,
    ) {}
}
