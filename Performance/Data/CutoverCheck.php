<?php

namespace App\Domains\People\Performance\Data;

/**
 * One readiness question and how many things fail it.
 *
 * `count` is what is wrong, so zero is ready. Reporting a percentage or a
 * "mostly" would defeat the purpose: somebody runs this to decide whether to
 * switch off the manual process, and that decision is binary.
 */
final readonly class CutoverCheck
{
    public function __construct(
        public string $check,
        public string $label,
        public int $count,
    ) {}

    public function ready(): bool
    {
        return $this->count === 0;
    }
}
