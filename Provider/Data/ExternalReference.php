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
        public string $providerId = self::PROVIDER_ID,
    ) {
        if (trim($providerId) === '' || trim($externalId) === '') {
            throw new \InvalidArgumentException('A workforce external reference provider and ID cannot be empty.');
        }
    }

    /** @return array{provider_id: string, resource_type: string, external_id: string} */
    public function jsonSerialize(): array
    {
        return [
            'provider_id' => $this->providerId,
            'resource_type' => $this->resourceType->value,
            'external_id' => $this->externalId,
        ];
    }
}
