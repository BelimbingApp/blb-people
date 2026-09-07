<?php

namespace App\Domains\People\Skills\Data;

use App\Domains\People\Skills\Models\SkillReminderDelivery;

/**
 * What one send or retry run did: three counts and the rows that failed.
 *
 * Skipped reminders are counted by reason rather than listed, because the
 * ordinary reason ("already delivered this week") is the common case a
 * scheduler produces every run and a list of it would be noise.
 */
final readonly class DeliveryRunResult
{
    /**
     * @param  list<SkillReminderDelivery>  $failures
     * @param  array<string, int>  $skipReasons
     */
    public function __construct(
        public int $sent,
        public int $skipped,
        public int $failed,
        public array $failures = [],
        public array $skipReasons = [],
    ) {}
}
