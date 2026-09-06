<?php

namespace App\Domains\People\Training\Enums;

use Carbon\CarbonImmutable;
use DateTimeInterface;

/**
 * When the HOD is asked whether training is being used.
 *
 * Three fixed distances from the end of the event, not a rolling window: the
 * question at thirty days ("has it started to show?") is a different question
 * from the one at ninety ("did it stick?"), and answering them on a schedule is
 * what makes the answers comparable across people and courses.
 */
enum EffectivenessCheckpoint: string
{
    case Day30 = 'day_30';
    case Day60 = 'day_60';
    case Day90 = 'day_90';

    public function days(): int
    {
        return match ($this) {
            self::Day30 => 30,
            self::Day60 => 60,
            self::Day90 => 90,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Day30 => '30 days',
            self::Day60 => '60 days',
            self::Day90 => '90 days',
        };
    }

    /** The moment this checkpoint opens for an event that ended when it did. */
    public function opensAfter(DateTimeInterface $eventEndedAt): CarbonImmutable
    {
        return CarbonImmutable::instance($eventEndedAt)->addDays($this->days());
    }

    /**
     * The checkpoint being asked right now, or null when none is.
     *
     * Only one is ever open. An unanswered thirty-day question does not stay
     * open once the sixty-day one has arrived — the later question supersedes
     * it, because an answer given at ninety days about the thirty-day mark is
     * not the thirty-day answer, it is a memory of one.
     */
    public static function openAt(DateTimeInterface $eventEndedAt, DateTimeInterface $now): ?self
    {
        $moment = CarbonImmutable::instance($now);
        $open = null;

        foreach (self::cases() as $checkpoint) {
            if ($moment->greaterThanOrEqualTo($checkpoint->opensAfter($eventEndedAt))) {
                $open = $checkpoint;
            }
        }

        return $open;
    }
}
