<?php

namespace App\Domains\People\Organisation\Data;

use App\Domains\People\Organisation\Enums\OrganisationPurpose;

final readonly class OrganisationDrillThrough
{
    /** @param list<OrganisationNode> $nodes */
    public function __construct(
        public OrganisationNode $source,
        public OrganisationPurpose $purpose,
        public array $nodes,
    ) {}
}
