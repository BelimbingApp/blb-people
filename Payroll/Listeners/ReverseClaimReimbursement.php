<?php

namespace App\Modules\People\Payroll\Listeners;

use App\Modules\People\Claim\Events\ClaimReimbursementReversed;
use App\Modules\People\Payroll\Services\PayrollContributionIntake;

class ReverseClaimReimbursement
{
    public const SOURCE_TYPE = 'claim_line';

    public function __construct(
        private readonly PayrollContributionIntake $intake,
    ) {}

    public function handle(ClaimReimbursementReversed $event): void
    {
        $this->intake->reverse(
            sourceType: self::SOURCE_TYPE,
            sourceId: $event->claimLineId,
            payItemCode: $event->payItemCode,
            periodAnchor: $event->occurredOn,
            reason: $event->reason,
        );
    }
}
