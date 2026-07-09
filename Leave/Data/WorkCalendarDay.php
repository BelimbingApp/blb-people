<?php

namespace App\Modules\People\Leave\Data;

use DateTimeImmutable;

class WorkCalendarDay
{
    public const DAYTYPE_WORKING = 'working';

    public const DAYTYPE_HOLIDAY = 'holiday';

    public const DAYTYPE_OFF_DAY = 'off_day';

    public const DAYTYPE_REST_DAY = 'rest_day';

    public function __construct(
        public readonly DateTimeImmutable $occursOn,
        public readonly string $daytype,
        public readonly ?string $label = null,
        public readonly ?string $source = null,
    ) {}

    public function isNonWorking(): bool
    {
        return $this->daytype !== self::DAYTYPE_WORKING;
    }
}
