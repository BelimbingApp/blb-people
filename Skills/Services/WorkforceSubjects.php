<?php

namespace App\Domains\People\Skills\Services;

use App\Domains\People\Provider\Contracts\ReadsWorkforceDirectory;
use App\Domains\People\Provider\Data\WorkforceCompany;
use App\Domains\People\Provider\Data\WorkforceEmployee;
use App\Domains\People\Provider\Data\WorkforceRemapFact;
use App\Domains\People\Provider\Enums\WorkforceResourceType;

/**
 * Skills' narrow adapter to the People-owned workforce-subject seam.
 */
final class WorkforceSubjects
{
    public function __construct(private readonly ReadsWorkforceDirectory $directory) {}

    public function resolve(
        int $tenantId,
        int $companyId,
        WorkforceResourceType $type,
        int $stableId,
    ): WorkforceCompany|WorkforceEmployee|null {
        if ($type === WorkforceResourceType::Company) {
            return $this->directory->company((string) $stableId);
        }

        foreach ($this->directory->employees((string) $companyId) as $employee) {
            if ($type === WorkforceResourceType::Employee
                && $employee->reference->externalId === (string) $stableId) {
                return $employee;
            }

            $reference = match ($type) {
                WorkforceResourceType::OrganizationUnit => $employee->organizationReference,
                WorkforceResourceType::Position => $employee->positionReference,
                WorkforceResourceType::User => $employee->userReference,
                default => null,
            };

            if ($reference?->externalId === (string) $stableId) {
                return $employee;
            }
        }

        return null;
    }

    /** @return list<WorkforceEmployee> */
    public function employees(int $companyId): array
    {
        return $this->directory->employees((string) $companyId);
    }

    public function employeeForUser(int $companyId, int $platformUserId): ?WorkforceEmployee
    {
        return $this->directory->employeeForUser((string) $companyId, $platformUserId);
    }

    public function remap(WorkforceResourceType $type, int $fromId, int $toId): ?WorkforceRemapFact
    {
        return $this->directory->remap($type, (string) $fromId, (string) $toId);
    }
}
