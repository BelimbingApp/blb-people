<?php

namespace App\Domains\People\Provider\Data;

use DateTimeImmutable;
use DateTimeInterface;
use JsonSerializable;

final readonly class WorkforceBootstrapPage implements JsonSerializable
{
    public const CONTRACT_VERSION = '1.0.0';

    public const PROVIDER_VERSION = '0.1.0';

    /**
     * @param  list<WorkforceEmployee>  $employees
     * @param  list<WorkforceCompany>  $companies
     * @param  list<WorkforceOrganizationUnit>  $organizationUnits
     */
    public function __construct(
        public array $employees,
        public array $companies,
        public array $organizationUnits,
        public DateTimeImmutable $asOf,
        public ?string $nextPageCursor,
        public ?string $resumeCursor,
        public bool $complete,
    ) {
        if (! $complete && ($nextPageCursor === null || trim($nextPageCursor) === '')) {
            throw new \InvalidArgumentException('An incomplete workforce bootstrap page requires a next-page cursor.');
        }

        if ($complete && $nextPageCursor !== null) {
            throw new \InvalidArgumentException('A complete workforce bootstrap page cannot have a next-page cursor.');
        }

        if (! $complete && $resumeCursor !== null) {
            throw new \InvalidArgumentException('Only a complete workforce bootstrap page may advance the resume cursor.');
        }

        if ($complete && ($resumeCursor === null || trim($resumeCursor) === '')) {
            throw new \InvalidArgumentException('A complete workforce bootstrap page requires a resume cursor.');
        }
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return [
            'provider_id' => ExternalReference::PROVIDER_ID,
            'provider_version' => self::PROVIDER_VERSION,
            'contract_version' => self::CONTRACT_VERSION,
            'supported_resources' => ['company', 'organization_unit', 'employee', 'user'],
            'as_of' => $this->asOf->format(DateTimeInterface::RFC3339_EXTENDED),
            'next_page_cursor' => $this->nextPageCursor,
            'resume_cursor' => $this->resumeCursor,
            'complete' => $this->complete,
            'companies' => array_map(
                static fn (WorkforceCompany $company): array => $company->jsonSerialize(),
                $this->companies,
            ),
            'organization_units' => array_map(
                static fn (WorkforceOrganizationUnit $unit): array => $unit->jsonSerialize(),
                $this->organizationUnits,
            ),
            'positions' => [],
            'employees' => array_map(
                static fn (WorkforceEmployee $employee): array => $employee->jsonSerialize(),
                $this->employees,
            ),
        ];
    }
}
