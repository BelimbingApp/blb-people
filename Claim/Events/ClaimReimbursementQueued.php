<?php

namespace App\Modules\People\Claim\Events;

use DateTimeImmutable;

/**
 * Producer-domain event: one approved claim line is ready to be paid
 * by a downstream payroll plugin.
 *
 * Emitted once per eligible line by ClaimPayrollHandoffService. Carries
 * the pay-item code as a snapshot of what was captured at claim
 * submission (operational state on ClaimLine, not payroll vocabulary
 * leakage; a later phase that moves the code to a Payroll-side mapping
 * removes this field from the event payload).
 */
final readonly class ClaimReimbursementQueued
{
    /**
     * @param  array<string, mixed>  $accountingSnapshot
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public int $companyId,
        public int $employeeId,
        public int $claimRequestId,
        public int $claimLineId,
        public int $claimTypeId,
        public string $payItemCode,
        public string $currency,
        public DateTimeImmutable $occurredOn,
        public float $amount,
        public string $label,
        public array $accountingSnapshot = [],
        public array $metadata = [],
    ) {}
}
