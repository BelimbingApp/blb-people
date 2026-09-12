<?php

namespace App\Domains\People\Training\Data;

use DateTimeInterface;

/**
 * Who a training passport's workforce record describes, and how fresh that
 * description is (0014-d).
 *
 * Names come from the provider directory, and a reference carries no name —
 * so `position` is the position's stable id, never a title. Everything but
 * the flags is null when the directory cannot be read: the passport still
 * renders its events, certificates and skills, marked unavailable.
 */
final readonly class TrainingPassportContext
{
    public function __construct(
        public ?string $displayName,
        public ?string $department,
        public ?string $position,
        public ?string $manager,
        public ?DateTimeInterface $observedAt,
        public bool $stale,
        public bool $unavailable,
    ) {}

    public static function unavailable(): self
    {
        return new self(null, null, null, null, null, false, true);
    }
}
