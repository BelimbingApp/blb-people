<?php

namespace App\Domains\People\Provider\Data;

use App\Domains\People\Provider\Enums\WorkforceResourceType;
use DateTimeImmutable;

final readonly class WorkforcePosition
{
    public function __construct(
        public ExternalReference $reference,
        public ExternalReference $companyReference,
        public string $name,
        public bool $active,
        public DateTimeImmutable $observedAt,
        public ?ExternalReference $organizationReference = null,
    ) {
        if ($reference->resourceType !== WorkforceResourceType::Position
            || $companyReference->resourceType !== WorkforceResourceType::Company
            || ($organizationReference !== null
                && $organizationReference->resourceType !== WorkforceResourceType::OrganizationUnit)) {
            throw new \InvalidArgumentException('Workforce positions contain a mismatched workforce reference type.');
        }

        if (trim($name) === '') {
            throw new \InvalidArgumentException('Workforce position names cannot be empty.');
        }
    }
}
