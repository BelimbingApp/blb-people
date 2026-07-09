<?php

namespace App\Modules\People\Leave\Data;

use DateTimeImmutable;

class PublicHoliday
{
    public const SCOPE_FEDERAL = 'federal';

    public const SCOPE_STATE = 'state';

    /**
     * @param  list<string>  $stateCodes  Empty when scope is federal.
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public readonly DateTimeImmutable $occursOn,
        public readonly string $name,
        public readonly string $scope,
        public readonly array $stateCodes = [],
        public readonly ?DateTimeImmutable $substitutedFrom = null,
        public readonly array $metadata = [],
    ) {}
}
