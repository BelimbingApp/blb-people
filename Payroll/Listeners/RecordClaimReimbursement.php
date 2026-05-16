<?php

namespace App\Modules\People\Payroll\Listeners;

use App\Modules\People\Claim\Events\ClaimReimbursementQueued;
use App\Modules\People\Payroll\Contracts\Intake\PayrollContributionPayload;
use App\Modules\People\Payroll\Services\PayrollContributionIntake;

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
