<?php

namespace App\Domains\People\Provider\Data;

use App\Domains\People\Provider\Enums\WorkforceResourceType;
use DateTimeImmutable;
use DateTimeInterface;
use JsonSerializable;

final readonly class WorkforceOrganizationUnit implements JsonSerializable
{
    public function __construct(
        public ExternalReference $reference,
        public ExternalReference $companyReference,
        public string $name,
        public bool $active,
        public DateTimeImmutable $effectiveAt,
        public DateTimeImmutable $observedAt,
        public ?string $code = null,
        public ?string $kind = null,
        public ?string $sourceVersion = null,
    ) {
        if ($reference->resourceType !== WorkforceResourceType::OrganizationUnit
            || $companyReference->resourceType !== WorkforceResourceType::Company) {
            throw new \InvalidArgumentException('Workforce organization units require organization and company references.');
        }

        if (trim($name) === '') {
            throw new \InvalidArgumentException('Workforce organization-unit names cannot be empty.');
        }
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return [
            'reference' => $this->reference->jsonSerialize(),
            'company_reference' => $this->companyReference->jsonSerialize(),
            'name' => $this->name,
            'active' => $this->active,
            'effective_at' => $this->effectiveAt->format(DateTimeInterface::RFC3339_EXTENDED),
            'observed_at' => $this->observedAt->format(DateTimeInterface::RFC3339_EXTENDED),
            'parent_reference' => null,
            'code' => $this->code,
            'kind' => $this->kind,
            'source_version' => $this->sourceVersion,
        ];
    }
}
