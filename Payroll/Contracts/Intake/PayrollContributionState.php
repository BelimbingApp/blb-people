<?php

namespace App\Modules\People\Payroll\Contracts\Intake;

/**
 * State vocabulary aligned to PayrollRun status. Returned by
 * PayrollContributionStatus and persisted on PayrollPendingContribution.
 */
final class PayrollContributionState
{
    public const ABSENT = 'absent';

    public const PENDING = 'pending';

    public const QUEUED_IN_RUN = 'queued_in_run';

    public const CALCULATED = 'calculated';

    public const CLOSED = 'closed';

    public const VOIDED = 'voided';

    public const REVERSED = 'reversed';

    public const REJECTED_LOCKED = 'rejected_locked';
}
