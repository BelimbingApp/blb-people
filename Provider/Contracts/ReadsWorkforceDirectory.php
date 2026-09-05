<?php

namespace App\Domains\People\Provider\Contracts;

use App\Domains\People\Provider\Data\WorkforceCompany;
use App\Domains\People\Provider\Data\WorkforceEmployee;
use App\Domains\People\Provider\Data\WorkforceOrganizationUnit;
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

    /**
     * The organisation units of one company, with the names a caller renders.
     *
     * A consumer cannot derive this from employees(): an employee carries an
     * ExternalReference for its unit and a reference has no name, and a unit
     * with nobody in it appears in no employee's record at all. That empty unit
     * is not an edge case — it is a department being stood up, which is exactly
     * when work gets scheduled against it.
     *
     * @return list<WorkforceOrganizationUnit>
     */
    public function organizationUnits(string $companyStableId): array;

    public function employeeForUser(string $companyStableId, int $platformUserId): ?WorkforceEmployee;

    public function remap(
        WorkforceResourceType $type,
        string $fromStableId,
        string $toStableId,
    ): ?WorkforceRemapFact;
}
