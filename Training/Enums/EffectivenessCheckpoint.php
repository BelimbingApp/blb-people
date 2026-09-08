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

    /**
     * The workbook intervals, as the last-resort fallback behind the config
     * file and the per-company policy. Not a standards requirement: see
     * docs/contracts/training-effectiveness.md.
     */
    private const WORKBOOK_OFFSETS = ['day_30' => 30, 'day_60' => 60, 'day_90' => 90];

    /**
     * The governed offset for this checkpoint, in days after the event ended.
     *
     * @param  array<string, int>  $offsets  keyed by case value, from
     *                                       TrainingEffectivenessPolicy
     */
    public function days(array $offsets): int
    {
        return (int) ($offsets[$this->value] ?? self::WORKBOOK_OFFSETS[$this->value]);
    }

    /**
     * The workbook 30/60/90 defaults, for a company with no policy row.
     *
     * @return array{day_30: int, day_60: int, day_90: int}
     */
    public static function defaultOffsets(): array
    {
        /** @var array<string, int> $configured */
        $configured = (array) config('people-training.effectiveness.checkpoint_days', []);

        return [
            self::Day30->value => (int) ($configured['day_30'] ?? self::WORKBOOK_OFFSETS['day_30']),
            self::Day60->value => (int) ($configured['day_60'] ?? self::WORKBOOK_OFFSETS['day_60']),
            self::Day90->value => (int) ($configured['day_90'] ?? self::WORKBOOK_OFFSETS['day_90']),
        ];
    }

    public function label(): string
    {
        return match ($this) {
            self::Day30 => '30 days',
            self::Day60 => '60 days',
            self::Day90 => '90 days',
        };
    }

    /**
     * The moment this checkpoint opens for an event that ended when it did.
     *
     * @param  array<string, int>|null  $offsets  the company policy in force
     *                                            when the event ended; null
     *                                            takes the config defaults
     */
    public function opensAfter(DateTimeInterface $eventEndedAt, ?array $offsets = null): CarbonImmutable
    {
        return CarbonImmutable::instance($eventEndedAt)->addDays($this->days($offsets ?? self::defaultOffsets()));
    }

    /**
     * Every checkpoint whose moment has passed, oldest first.
     *
     * Distinct from openAt(), which answers "what is being asked now" and
     * returns one. An aggregate needs "what has been asked at all", because
     * the honest denominator for an answer rate is every checkpoint that
     * opened — including the ones a later checkpoint superseded unanswered.
     *
     * @return list<self>
     */
    public static function elapsedAt(
        DateTimeInterface $eventEndedAt,
        DateTimeInterface $now,
        ?array $offsets = null,
    ): array {
        $moment = CarbonImmutable::instance($now);
        $offsets ??= self::defaultOffsets();

        return array_values(array_filter(
            self::cases(),
            static fn (self $checkpoint): bool => $moment->greaterThanOrEqualTo(
                $checkpoint->opensAfter($eventEndedAt, $offsets),
            ),
        ));
    }

    /**
     * The checkpoint being asked right now, or null when none is.
     *
     * Only one is ever open. An unanswered thirty-day question does not stay
     * open once the sixty-day one has arrived — the later question supersedes
     * it, because an answer given at ninety days about the thirty-day mark is
     * not the thirty-day answer, it is a memory of one.
     */
    public static function openAt(
        DateTimeInterface $eventEndedAt,
        DateTimeInterface $now,
        ?array $offsets = null,
    ): ?self {
        $moment = CarbonImmutable::instance($now);
        $offsets ??= self::defaultOffsets();
        $open = null;

        foreach (self::cases() as $checkpoint) {
            if ($moment->greaterThanOrEqualTo($checkpoint->opensAfter($eventEndedAt, $offsets))) {
                $open = $checkpoint;
            }
        }

        return $open;
    }
}
