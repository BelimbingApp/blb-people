<?php

namespace App\Modules\People\Claim\Data;

readonly class ClaimSubmissionSummary
{
    /**
     * @param  array<string, mixed>  $strictest
     * @param  list<array<string, mixed>>  $duplicateRisks
     */
    public function __construct(
        public array $strictest,
        public float $requestedTotal,
        public int $lineCount,
        public array $duplicateRisks,
    ) {}
}
