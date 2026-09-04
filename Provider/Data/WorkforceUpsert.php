<?php

namespace App\Domains\People\Provider\Data;

use DateTimeImmutable;
use DateTimeInterface;
use JsonSerializable;

/** A company, organization unit or employee whose current facts changed. */
final readonly class WorkforceUpsert implements JsonSerializable
{
    public function __construct(
        public WorkforceCompany|WorkforceOrganizationUnit|WorkforceEmployee $record,
        public DateTimeImmutable $occurredAt,
    ) {}

    public function jsonSerialize(): array
    {
        return [
            'type' => 'upsert',
            'resource_type' => $this->record->reference->resourceType->value,
            'occurred_at' => $this->occurredAt->format(DateTimeInterface::RFC3339_EXTENDED),
            'record' => $this->record->jsonSerialize(),
        ];
    }
}
