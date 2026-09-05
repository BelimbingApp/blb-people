<?php

namespace App\Domains\People\Progression\Data;

/** The published policy identity/version, never an eligibility or compensation decision. */
final readonly class PublishedProgressionPolicy
{
    public function __construct(
        public int $tenantId,
        public int $companyId,
        public string $policyId,
        public string $version,
    ) {}
}
