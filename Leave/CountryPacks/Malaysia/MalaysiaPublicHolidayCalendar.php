<?php

namespace App\Modules\People\Leave\CountryPacks\Malaysia;

use App\Modules\People\Leave\Contracts\ProvidesPublicHolidayCalendar;
use App\Modules\People\Leave\Data\PublicHoliday;
use DateTimeImmutable;

class MalaysiaPublicHolidayCalendar implements ProvidesPublicHolidayCalendar
{
    /**
     * Federal gazetted public holidays for Malaysia.
     * Source: Public Holidays Act 1951 gazette + Ministry of Human Resources annual proclamation.
     * State-specific holidays (Sultan birthdays, state-only Thaipusam, etc.) are not included here yet.
     */
    private const FEDERAL_2026 = [
        ['2026-01-01', 'New Year\'s Day'],
        ['2026-02-01', 'Federal Territory Day'],
        ['2026-02-17', 'Chinese New Year'],
        ['2026-02-18', 'Chinese New Year (Day 2)'],
        ['2026-03-21', 'Hari Raya Aidilfitri (Day 1, provisional)'],
        ['2026-03-22', 'Hari Raya Aidilfitri (Day 2, provisional)'],
        ['2026-05-01', 'Labour Day'],
        ['2026-05-31', 'Wesak Day (provisional)'],
        ['2026-05-28', 'Hari Raya Aidiladha (provisional)'],
        ['2026-06-01', 'Yang di-Pertuan Agong\'s Birthday (provisional)'],
        ['2026-06-17', 'Awal Muharram / Maal Hijrah (provisional)'],
        ['2026-08-25', 'Maulidur Rasul (provisional)'],
        ['2026-08-31', 'National Day (Hari Merdeka)'],
        ['2026-09-16', 'Malaysia Day'],
        ['2026-10-31', 'Deepavali (provisional)'],
        ['2026-12-25', 'Christmas Day'],
    ];

    public function publicHolidaysForYear(int $year, ?string $stateCode = null): array
    {
        if ($year !== 2026) {
            return [];
        }

        $holidays = [];
        foreach (self::FEDERAL_2026 as [$date, $name]) {
            $holidays[] = new PublicHoliday(
                occursOn: new DateTimeImmutable($date),
                name: $name,
                scope: PublicHoliday::SCOPE_FEDERAL,
            );
        }

        return $holidays;
    }

    public function publishedYears(): array
    {
        return [2026];
    }
}
