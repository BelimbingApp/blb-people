<?php

namespace App\Modules\People\Leave\CountryPacks\Malaysia;

use App\Modules\People\Leave\Contracts\ProvidesPublicHolidayCalendar;
use App\Modules\People\Leave\Data\PublicHoliday;
use DateTimeImmutable;

class MalaysiaPublicHolidayCalendar implements ProvidesPublicHolidayCalendar
{
    private const STATE_KUALA_LUMPUR = 'KUL';

    private const STATE_SELANGOR = 'SGR';

    private const FEBRUARY_1_2026 = '2026-02-01';

    /**
     * 2026 federal gazetted holidays.
     *
     * Dates that depend on moon sighting remain marked provisional in metadata.
     * We currently model Sunday-substitution for the standard Sunday-weekend
     * states used by the first leave slice (federal default, Kuala Lumpur,
     * Selangor).
     *
     * @var list<array{name: string, date: string, provisional?: bool}>
     */
    private const FEDERAL_2026 = [
        ['date' => '2026-02-17', 'name' => 'Chinese New Year'],
        ['date' => '2026-02-18', 'name' => 'Chinese New Year (Day 2)'],
        ['date' => '2026-03-21', 'name' => 'Hari Raya Aidilfitri (Day 1)', 'provisional' => true],
        ['date' => '2026-03-22', 'name' => 'Hari Raya Aidilfitri (Day 2)', 'provisional' => true],
        ['date' => '2026-05-01', 'name' => 'Labour Day'],
        ['date' => '2026-05-28', 'name' => 'Hari Raya Aidiladha', 'provisional' => true],
        ['date' => '2026-05-31', 'name' => 'Wesak Day', 'provisional' => true],
        ['date' => '2026-06-01', 'name' => "Yang di-Pertuan Agong's Birthday", 'provisional' => true],
        ['date' => '2026-06-17', 'name' => 'Awal Muharram / Maal Hijrah', 'provisional' => true],
        ['date' => '2026-08-25', 'name' => 'Maulidur Rasul', 'provisional' => true],
        ['date' => '2026-08-31', 'name' => 'National Day (Hari Merdeka)'],
        ['date' => '2026-09-16', 'name' => 'Malaysia Day'],
        ['date' => '2026-11-08', 'name' => 'Deepavali', 'provisional' => true],
        ['date' => '2026-12-25', 'name' => 'Christmas Day'],
    ];

    /**
     * 2026 state-only holidays needed by the first pack slice.
     *
     * @var array<string, list<array{name: string, date: string, provisional?: bool}>>
     */
    private const STATE_2026 = [
        self::STATE_KUALA_LUMPUR => [
            ['date' => self::FEBRUARY_1_2026, 'name' => 'Federal Territory Day'],
            ['date' => self::FEBRUARY_1_2026, 'name' => 'Thaipusam'],
        ],
        self::STATE_SELANGOR => [
            ['date' => self::FEBRUARY_1_2026, 'name' => 'Thaipusam'],
            ['date' => '2026-03-07', 'name' => 'Nuzul Al-Quran'],
            ['date' => '2026-12-11', 'name' => "Sultan of Selangor's Birthday"],
        ],
    ];

    public function publicHolidaysForYear(int $year, ?string $stateCode = null): array
    {
        if ($year !== 2026) {
            return [];
        }

        $normalizedState = $this->normalizeStateCode($stateCode);
        $rows = $this->holidayRowsForYear($normalizedState);
        $rows = [...$rows, ...$this->substituteRows($rows, $normalizedState)];

        return $this->coalesceRows($rows);
    }

    public function publishedYears(): array
    {
        return [2026];
    }

    /**
     * @return list<array{
     *   occurs_on: DateTimeImmutable,
     *   name: string,
     *   scope: string,
     *   state_codes: list<string>,
     *   substituted_from: ?DateTimeImmutable,
     *   metadata: array<string, mixed>
     * }>
     */
    private function holidayRowsForYear(?string $stateCode): array
    {
        $rows = [];

        foreach (self::FEDERAL_2026 as $holiday) {
            $rows[] = $this->row(
                date: $holiday['date'],
                name: $holiday['name'],
                scope: PublicHoliday::SCOPE_FEDERAL,
                metadata: $this->metadataFor($holiday),
            );
        }

        if ($stateCode !== null && array_key_exists($stateCode, self::STATE_2026)) {
            foreach (self::STATE_2026[$stateCode] as $holiday) {
                $rows[] = $this->row(
                    date: $holiday['date'],
                    name: $holiday['name'],
                    scope: PublicHoliday::SCOPE_STATE,
                    stateCodes: [$stateCode],
                    metadata: $this->metadataFor($holiday),
                );
            }
        }

        return $rows;
    }

    /**
     * @param  list<array{
     *   occurs_on: DateTimeImmutable,
     *   name: string,
     *   scope: string,
     *   state_codes: list<string>,
     *   substituted_from: ?DateTimeImmutable,
     *   metadata: array<string, mixed>
     * }>  $rows
     * @return list<array{
     *   occurs_on: DateTimeImmutable,
     *   name: string,
     *   scope: string,
     *   state_codes: list<string>,
     *   substituted_from: ?DateTimeImmutable,
     *   metadata: array<string, mixed>
     * }>
     */
    private function substituteRows(array $rows, ?string $stateCode): array
    {
        $occupiedDates = [];
        foreach ($rows as $row) {
            $occupiedDates[$row['occurs_on']->format('Y-m-d')] = true;
        }

        $substitutions = [];

        foreach ($rows as $row) {
            if (! $this->requiresSundaySubstitution($row['occurs_on'], $stateCode)) {
                continue;
            }

            $substituteDate = $row['occurs_on']->modify('+1 day');
            while (isset($occupiedDates[$substituteDate->format('Y-m-d')])) {
                $substituteDate = $substituteDate->modify('+1 day');
            }

            $substitutions[] = [
                'occurs_on' => $substituteDate,
                'name' => $row['name'].' (Substitute)',
                'scope' => $row['scope'],
                'state_codes' => $row['state_codes'],
                'substituted_from' => $row['occurs_on'],
                'metadata' => $row['metadata'] + ['substitute_holiday' => true],
            ];
            $occupiedDates[$substituteDate->format('Y-m-d')] = true;
        }

        return $substitutions;
    }

    /**
     * @param  list<array{
     *   occurs_on: DateTimeImmutable,
     *   name: string,
     *   scope: string,
     *   state_codes: list<string>,
     *   substituted_from: ?DateTimeImmutable,
     *   metadata: array<string, mixed>
     * }>  $rows
     * @return list<PublicHoliday>
     */
    private function coalesceRows(array $rows): array
    {
        usort($rows, fn (array $left, array $right): int => $left['occurs_on'] <=> $right['occurs_on']);

        $coalesced = [];
        foreach ($rows as $row) {
            $key = $row['occurs_on']->format('Y-m-d');

            if (! isset($coalesced[$key])) {
                $coalesced[$key] = $row;

                continue;
            }

            $existing = $coalesced[$key];
            $coalesced[$key] = [
                'occurs_on' => $existing['occurs_on'],
                'name' => $existing['name'].' / '.$row['name'],
                'scope' => $existing['scope'] === PublicHoliday::SCOPE_STATE || $row['scope'] === PublicHoliday::SCOPE_STATE
                    ? PublicHoliday::SCOPE_STATE
                    : PublicHoliday::SCOPE_FEDERAL,
                'state_codes' => array_values(array_unique([...$existing['state_codes'], ...$row['state_codes']])),
                'substituted_from' => $existing['substituted_from'] ?? $row['substituted_from'],
                'metadata' => $existing['metadata'] + $row['metadata'],
            ];
        }

        return array_values(array_map(
            fn (array $row): PublicHoliday => new PublicHoliday(
                occursOn: $row['occurs_on'],
                name: $row['name'],
                scope: $row['scope'],
                stateCodes: $row['state_codes'],
                substitutedFrom: $row['substituted_from'],
                metadata: $row['metadata'],
            ),
            $coalesced,
        ));
    }

    /**
     * @param  array{name: string, date: string, provisional?: bool}  $holiday
     * @return array<string, mixed>
     */
    private function metadataFor(array $holiday): array
    {
        return [
            'year' => 2026,
            'provisional' => (bool) ($holiday['provisional'] ?? false),
        ];
    }

    /**
     * @param  list<string>  $stateCodes
     * @param  array<string, mixed>  $metadata
     * @return array{
     *   occurs_on: DateTimeImmutable,
     *   name: string,
     *   scope: string,
     *   state_codes: list<string>,
     *   substituted_from: ?DateTimeImmutable,
     *   metadata: array<string, mixed>
     * }
     */
    private function row(
        string $date,
        string $name,
        string $scope,
        array $stateCodes = [],
        ?DateTimeImmutable $substitutedFrom = null,
        array $metadata = [],
    ): array {
        return [
            'occurs_on' => new DateTimeImmutable($date),
            'name' => $name,
            'scope' => $scope,
            'state_codes' => $stateCodes,
            'substituted_from' => $substitutedFrom,
            'metadata' => $metadata,
        ];
    }

    private function requiresSundaySubstitution(DateTimeImmutable $date, ?string $stateCode): bool
    {
        return $date->format('w') === '0'
            && in_array($stateCode, [null, self::STATE_KUALA_LUMPUR, self::STATE_SELANGOR], true);
    }

    private function normalizeStateCode(?string $stateCode): ?string
    {
        if ($stateCode === null) {
            return null;
        }

        return match (strtoupper(trim($stateCode))) {
            'KL', 'KUL', 'WPKL', 'MY-14' => self::STATE_KUALA_LUMPUR,
            'SEL', 'SGR', 'SELANGOR', 'MY-10' => self::STATE_SELANGOR,
            default => strtoupper(trim($stateCode)),
        };
    }
}
