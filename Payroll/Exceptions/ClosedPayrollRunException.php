<?php

namespace App\Modules\People\Payroll\Exceptions;

use App\Base\Foundation\Exceptions\BlbInvariantViolationException;

class ClosedPayrollRunException extends BlbInvariantViolationException
{
    public function __construct(int|string|null $runId, string $status)
    {
        parent::__construct(
            "Payroll run {$runId} is {$status} and cannot be recalculated or changed.",
            context: [
                'payroll_run_id' => $runId,
                'status' => $status,
            ],
        );
    }
}
