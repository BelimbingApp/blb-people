<?php

namespace App\Domains\People\Provider\Data;

use App\Domains\People\Provider\Enums\WorkforceResourceType;
use JsonSerializable;

final readonly class ExternalReference implements JsonSerializable
{
    public const PROVIDER_ID = 'blb-people';

    public function __construct(
        public WorkforceResourceType $resourceType,
        public string $externalId,
    ) {
        if (trim($externalId) === '') {
            throw new \InvalidArgumentException('A workforce external reference cannot be empty.');
        }
    }

    /** @return array{provider_id: string, resource_type: string, external_id: string} */
    public function jsonSerialize(): array
    {
        return [
            'provider_id' => self::PROVIDER_ID,
            'resource_type' => $this->resourceType->value,
            'external_id' => $this->externalId,
        ];
    }
}
