<?php

namespace App\Domains\People\Provider\Data;

use App\Domains\People\Provider\Enums\WorkforceResourceType;

final readonly class WorkforceSubject
{
    public function __construct(
        public ?int $tenantId,
        public ?int $companyId,
        public WorkforceResourceType $type,
        public string $stableId,
        public ?ExternalReference $externalReference = null,
    ) {
        if (trim($stableId) === '') {
            throw new \InvalidArgumentException('A workforce subject stable ID cannot be empty.');
        }

        if ($externalReference !== null && $externalReference->resourceType !== $type) {
            throw new \InvalidArgumentException('A workforce subject external reference must have the subject type.');
        }
    }
}
