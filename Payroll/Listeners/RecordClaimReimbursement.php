<?php

namespace App\Domains\People\Payroll\Listeners;

use App\Domains\People\Claim\Events\ClaimReimbursementQueued;
use App\Domains\People\Payroll\Contracts\Intake\PayrollContributionPayload;
use App\Domains\People\Payroll\Services\PayrollContributionIntake;

class RecordClaimReimbursement
{
    public const SOURCE_TYPE = 'claim_line';

    public function __construct(
        private readonly PayrollContributionIntake $intake,
    ) {}

    public function handle(ClaimReimbursementQueued $event): void
    {
        $this->intake->ingest(new PayrollContributionPayload(
            sourceType: self::SOURCE_TYPE,
            sourceId: $event->claimLineId,
            payItemCode: $event->payItemCode,
            periodAnchor: $event->occurredOn,
            companyId: $event->companyId,
            employeeId: $event->employeeId,
            currency: $event->currency,
            occurredOn: $event->occurredOn,
            inputType: 'reimbursement',
            amount: $event->amount,
            quantity: 1.0,
            rate: null,
            label: $event->label,
            accountingSnapshot: $event->accountingSnapshot,
            metadata: $event->metadata,
        ));
    }
}
