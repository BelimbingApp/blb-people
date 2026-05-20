<?php

namespace App\Modules\People\Attendance\Data;

readonly class ClockEventAttributes
{
    /**
     * @param  array<string, mixed>  $evidence
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public ?string $timezone = null,
        public ?int $actorUserId = null,
        public ?string $sourceSystem = null,
        public ?string $sourceLabel = null,
        public ?string $sourceCode = null,
        public ?int $correctsClockEventId = null,
        public array $evidence = [],
        public array $metadata = [],
    ) {}
}
