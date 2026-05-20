<?php

namespace App\Modules\People\Leave\Data;

final readonly class LeaveDaysPreviewOptions
{
    public function __construct(
        public ?float $hoursCount = null,
        public ?string $countryIso = null,
        public ?string $stateCode = null,
        public ?string $portionOverride = null,
    ) {}
}
