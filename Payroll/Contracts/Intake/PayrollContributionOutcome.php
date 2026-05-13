<?php

namespace App\Modules\People\Payroll\Contracts\Intake;

/**
 * Structured result returned by PayrollContributionIntake::ingest and ::reverse.
 *
 * Producers branch on $state (one of PayrollContributionState constants) and use
 * the references to surface the outcome to users without needing to re-query.
 */
final readonly class PayrollContributionOutcome
{
    public function __construct(
        public string $state,
        public ?int $payrollInputId = null,
        public ?int $payrollRunId = null,
        public ?string $payrollRunStatus = null,
        public ?int $payrollPendingContributionId = null,
        public ?string $reason = null,
    ) {}

    public function isMaterialized(): bool
    {
        return $this->payrollInputId !== null;
    }

    public function isPending(): bool
    {
        return $this->state === PayrollContributionState::PENDING;
    }

    public function isRejected(): bool
    {
        return $this->state === PayrollContributionState::REJECTED_LOCKED;
    }
}
