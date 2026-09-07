<?php

namespace App\Domains\People\Training\Data;

/**
 * One checkpoint's numbers for one course.
 *
 * `answerRate` and `meanRating` are null rather than zero when nothing has
 * opened or nothing was answered: nobody failed to answer a question that was
 * never asked, and a zero there reads as a department ignoring it.
 */
final readonly class CheckpointEffectiveness
{
    public function __construct(
        public int $opened,
        public int $answered,
        public ?int $answerRate,
        public ?float $meanRating,
    ) {}
}
