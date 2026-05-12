<?php

namespace App\Modules\People\Leave\Data;

class LeaveDaysPreview
{
    /**
     * @param  list<LeaveDayBreakdown>  $days
     * @param  list<string>  $warnings  Non-blocking notes (e.g. "Saturday counted as half-day").
     */
    public function __construct(
        public readonly array $days,
        public readonly float $totalCountedDays,
        public readonly float $totalCountedHours,
        public readonly array $warnings = [],
    ) {}
}
