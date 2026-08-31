<?php

namespace App\Domains\People\Provider\Data;

use App\Domains\People\Provider\Enums\WorkforceResourceType;
use DateTimeImmutable;
use DateTimeInterface;
use JsonSerializable;

final readonly class WorkforceEmployee implements JsonSerializable
{
    public function __construct(
        public ExternalReference $reference,
        public ExternalReference $companyReference,
        public string $displayName,
        public bool $active,
        public DateTimeImmutable $effectiveAt,
        public DateTimeImmutable $observedAt,
        public ?string $employeeNumber = null,
        public ?string $email = null,
        public ?ExternalReference $userReference = null,
        public ?ExternalReference $organizationReference = null,
        public ?ExternalReference $managerReference = null,
        public ?ExternalReference $departmentHeadReference = null,
        public ?string $sourceVersion = null,
    ) {
        if ($reference->resourceType !== WorkforceResourceType::Employee
            || $companyReference->resourceType !== WorkforceResourceType::Company
            || ($userReference !== null && $userReference->resourceType !== WorkforceResourceType::User)
            || ($organizationReference !== null && $organizationReference->resourceType !== WorkforceResourceType::OrganizationUnit)
            || ($managerReference !== null && $managerReference->resourceType !== WorkforceResourceType::Employee)
            || ($departmentHeadReference !== null && $departmentHeadReference->resourceType !== WorkforceResourceType::Employee)) {
            throw new \InvalidArgumentException('Workforce employees contain a mismatched workforce reference type.');
        }

        if (trim($displayName) === '') {
            throw new \InvalidArgumentException('Workforce employee display names cannot be empty.');
        }
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return [
            'reference' => $this->reference->jsonSerialize(),
            'company_reference' => $this->companyReference->jsonSerialize(),
            'display_name' => $this->displayName,
            'active' => $this->active,
            'effective_at' => $this->effectiveAt->format(DateTimeInterface::RFC3339_EXTENDED),
            'observed_at' => $this->observedAt->format(DateTimeInterface::RFC3339_EXTENDED),
            'employee_number' => $this->employeeNumber,
            'email' => $this->email,
            'user_reference' => $this->userReference?->jsonSerialize(),
            'organization_reference' => $this->organizationReference?->jsonSerialize(),
            'position_reference' => null,
            'manager_reference' => $this->managerReference?->jsonSerialize(),
            'department_head_reference' => $this->departmentHeadReference?->jsonSerialize(),
            'source_version' => $this->sourceVersion,
        ];
    }
}
