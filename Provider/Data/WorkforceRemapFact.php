<?php

namespace App\Domains\People\Provider\Data;

use App\Domains\People\Provider\Enums\WorkforceResourceType;
use DateTimeImmutable;

final readonly class WorkforceRemapFact
{
    public function __construct(
        public WorkforceResourceType $type,
        public string $fromStableId,
        public string $toStableId,
        public string $auditReference,
        public DateTimeImmutable $recordedAt,
    ) {
        if (trim($fromStableId) === '' || trim($toStableId) === '' || trim($auditReference) === '') {
            throw new \InvalidArgumentException('A workforce remap requires both stable IDs and an audit reference.');
        }

        if ($fromStableId === $toStableId) {
            throw new \InvalidArgumentException('A workforce remap must change the stable ID.');
        }
    }
}
