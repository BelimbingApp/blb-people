<?php

namespace App\Domains\People\Provider\Contracts;

use App\Domains\People\Provider\Data\WorkforcePosition;

interface ReadsWorkforcePositions
{
    /** @return list<WorkforcePosition> */
    public function positions(string $companyStableId): array;
}
