<?php

namespace App\Modules\People\Leave\Data;

class StatutoryEntitlementPolicy
{
    /**
     * @param  list<StatutoryEntitlementBand>  $bands
     * @param  array<string, mixed>  $eligibilityPredicate
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public readonly string $leaveTypeCode,
        public readonly string $code,
        public readonly string $name,
        public readonly string $accrualMethod,
        public readonly array $bands,
        public readonly bool $prorateForJoiners = true,
        public readonly bool $prorateForLeavers = true,
        public readonly ?float $aggregateCapDays = null,
        public readonly array $eligibilityPredicate = [],
        public readonly array $metadata = [],
    ) {}
}
