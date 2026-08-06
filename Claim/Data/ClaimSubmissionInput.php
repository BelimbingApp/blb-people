<?php

namespace App\Domains\People\Claim\Data;

use App\Core\Employee\Models\Employee;
use DateTimeImmutable;

readonly class ClaimSubmissionInput
{
    /** @param list<int> $combinedClaimTypeIds */
    public function __construct(
        public int $employeeId,
        public DateTimeImmutable $incurredOn,
        public float $requestedAmount,
        public int $attachmentCount,
        public ?string $providerName,
        public array $combinedClaimTypeIds = [],
        public ?Employee $employee = null,
    ) {}
}
