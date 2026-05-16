<?php

namespace App\Modules\People\Claim\Events;

use DateTimeImmutable;

/**
 * Producer-domain event: a previously queued claim line is being
 * reversed (cancellation, admin correction). The downstream consumer
 * is responsible for deleting or compensating its corresponding
 * `PayrollInput`.
 */
final readonly class ClaimReimbursementReversed
{
    public function __construct(
        public int $claimRequestId,
        public int $claimLineId,
        public string $payItemCode,
        public DateTimeImmutable $occurredOn,
        public ?string $reason = null,
    ) {}
}
