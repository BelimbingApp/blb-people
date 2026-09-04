<?php

namespace App\Domains\People\Provider\Data;

use DateTimeImmutable;
use DateTimeInterface;
use JsonSerializable;

/**
 * A reference that stopped existing as a current record, as distinct from
 * one that is still there with active = false. Native People emits this only
 * for a soft-deleted company; employees and departments are never deleted
 * from underneath the projection, they are retired in place.
 */
final readonly class WorkforceDeactivation implements JsonSerializable
{
    public function __construct(
        public ExternalReference $reference,
        public DateTimeImmutable $occurredAt,
    ) {}

    public function jsonSerialize(): array
    {
        return [
            'type' => 'deactivation',
            'resource_type' => $this->reference->resourceType->value,
            'occurred_at' => $this->occurredAt->format(DateTimeInterface::RFC3339_EXTENDED),
            'reference' => $this->reference->jsonSerialize(),
        ];
    }
}
