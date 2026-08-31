<?php

namespace App\Domains\People\Provider\Data;

use App\Domains\People\Provider\Enums\WorkforceResourceType;
use DateTimeImmutable;
use DateTimeInterface;
use JsonSerializable;

final readonly class WorkforceCompany implements JsonSerializable
{
    public function __construct(
        public ExternalReference $reference,
        public string $name,
        public bool $active,
        public DateTimeImmutable $observedAt,
        public ?string $code = null,
        public ?string $sourceVersion = null,
    ) {
        if ($reference->resourceType !== WorkforceResourceType::Company) {
            throw new \InvalidArgumentException('Workforce companies require a company reference.');
        }

        if (trim($name) === '') {
            throw new \InvalidArgumentException('Workforce company names cannot be empty.');
        }
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return [
            'reference' => $this->reference->jsonSerialize(),
            'name' => $this->name,
            'active' => $this->active,
            'observed_at' => $this->observedAt->format(DateTimeInterface::RFC3339_EXTENDED),
            'code' => $this->code,
            'source_version' => $this->sourceVersion,
        ];
    }
}
