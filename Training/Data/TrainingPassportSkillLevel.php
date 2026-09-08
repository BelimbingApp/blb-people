<?php

namespace App\Domains\People\Training\Data;

use DateTimeInterface;

/**
 * One line of the printable passport's skill-level section: the employee's
 * current level for a skill from the canonical score projection.
 */
final readonly class TrainingPassportSkillLevel
{
    public function __construct(
        public int $skillId,
        public string $code,
        public string $name,
        public int $currentLevel,
        public int $requiredLevel,
        public ?DateTimeInterface $assessedAt,
        public ?DateTimeInterface $validUntil,
    ) {}
}
