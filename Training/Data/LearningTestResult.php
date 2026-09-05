<?php

namespace App\Domains\People\Training\Data;

use App\Domains\People\Training\Exceptions\InvalidTrainingParticipationException;

final readonly class LearningTestResult
{
    public function __construct(
        public bool $applicable,
        public ?float $score = null,
        public ?float $maximum = null,
        public ?float $passMark = null,
    ) {
        if ((! $applicable && ($score !== null || $maximum !== null || $passMark !== null))
            || ($score !== null && ($maximum === null || $passMark === null))
            || ($maximum !== null && (! is_finite($maximum) || $maximum <= 0))
            || ($score !== null && (! is_finite($score) || $score < 0 || $score > $maximum))
            || ($passMark !== null && (! is_finite($passMark) || $maximum === null || $passMark < 0 || $passMark > $maximum))) {
            throw new InvalidTrainingParticipationException('A learning result needs a valid declared scale and pass mark.');
        }
    }

    public function toArray(): array
    {
        return [
            'applicable' => $this->applicable,
            'score' => $this->score,
            'maximum' => $this->maximum,
            'pass_mark' => $this->passMark,
            'passed' => $this->score === null ? null : $this->score >= $this->passMark,
        ];
    }
}
