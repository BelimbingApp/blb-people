<?php

namespace App\Domains\People\Leave\Contracts;

use App\Domains\People\Leave\Data\PublicHoliday;

interface ProvidesPublicHolidayCalendar
{
    /**
     * @return list<PublicHoliday>
     */
    public function publicHolidaysForYear(int $year, ?string $stateCode = null): array;

    /** @return list<int> Years for which the pack has published holiday data. */
    public function publishedYears(): array;
}
