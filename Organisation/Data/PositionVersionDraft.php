<?php

namespace App\Domains\People\Organisation\Data;

use DateTimeInterface;

/**
 * One immutable revision of a position's attributes, valid over an interval.
 * The interval belongs to the position, not to whoever holds it: a vacancy
 * does not end a position version.
 */
final readonly class PositionVersionDraft
{
    public function __construct(
        public string $positionStableId,
        public int $version,
        public string $title,
        public DateTimeInterface $effectiveFrom,
        public ?DateTimeInterface $effectiveTo = null,
    ) {}
}
