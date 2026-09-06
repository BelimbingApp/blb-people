<?php

namespace App\Domains\People\Performance\Data;

use DateTimeInterface;

/**
 * One attributable piece of workplace evidence over a measurement window.
 *
 * The source reference and version travel with it because a late source
 * correction has to be traceable to what it corrected — plan 0009 asks for a
 * versioned correction, not a silent overwrite.
 */
final readonly class ObservationDraft
{
    public function __construct(
        public int $employeeEntityId,
        public DateTimeInterface $windowStart,
        public DateTimeInterface $windowEnd,
        public string $evidence,
        public ?string $sourceReference = null,
        public ?int $sourceVersion = null,
    ) {}
}
