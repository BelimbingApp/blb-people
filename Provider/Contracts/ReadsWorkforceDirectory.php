<?php

namespace App\Domains\People\Provider\Contracts;

use App\Domains\People\Provider\Data\WorkforceCompany;
use App\Domains\People\Provider\Data\WorkforceEmployee;
use App\Domains\People\Provider\Data\WorkforceRemapFact;
use App\Domains\People\Provider\Enums\WorkforceResourceType;

/**
 * Narrow R2 directory seam. Stable IDs belong to the selected provider; a
 * consumer must never reinterpret an unmatched provider ID as a platform ID.
 */
interface ReadsWorkforceDirectory
{
    public function companyForPlatform(int $platformCompanyId): ?WorkforceCompany;

    public function company(string $companyStableId): ?WorkforceCompany;

    /** @return list<WorkforceEmployee> */
    public function employees(string $companyStableId): array;

    public function employeeForUser(string $companyStableId, int $platformUserId): ?WorkforceEmployee;

    public function remap(
        WorkforceResourceType $type,
        string $fromStableId,
        string $toStableId,
    ): ?WorkforceRemapFact;
}
