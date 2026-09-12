<?php

namespace App\Domains\People\Training\Data;

use App\Domains\People\Training\Exceptions\InvalidTrainingParticipationException;

final readonly class LearningTestResult
{
    /**
     * A score needs its scale, but a verdict needs a declared pass mark: a
     * sheet-recorded score (0011-c) arrives with the 0–100 scale and no pass
     * mark, so it is kept with a null verdict rather than an invented one.
     */
    public function __construct(
        public bool $applicable,
        public ?float $score = null,
        public ?float $maximum = null,
        public ?float $passMark = null,
    ) {
        if ((! $applicable && ($score !== null || $maximum !== null || $passMark !== null))
            || ($score !== null && $maximum === null)
            || ($maximum !== null && (! is_finite($maximum) || $maximum <= 0))
            || ($score !== null && (! is_finite($score) || $score < 0 || $score > $maximum))
            || ($passMark !== null && (! is_finite($passMark) || $maximum === null || $passMark < 0 || $passMark > $maximum))) {
            throw new InvalidTrainingParticipationException('A learning result needs a valid declared scale, and a pass mark to carry a verdict.');
        }
    }

    public function toArray(): array
    {
        return [
            'applicable' => $this->applicable,
            'score' => $this->score,
            'maximum' => $this->maximum,
            'pass_mark' => $this->passMark,
            'passed' => $this->score === null || $this->passMark === null ? null : $this->score >= $this->passMark,
        ];
    }
}
