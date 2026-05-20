<?php

namespace App\Modules\People\Leave\Data;

use DateTimeImmutable;

readonly class LeaveSubmissionContext
{
    /** @param array<string, mixed> $options */
    public function __construct(
        public DateTimeImmutable $startsOn,
        public DateTimeImmutable $endsOn,
        public string $unit,
        public int $attachmentCount,
        public array $options,
    ) {}
}
