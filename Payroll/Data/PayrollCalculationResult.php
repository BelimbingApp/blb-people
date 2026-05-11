<?php

namespace App\Modules\People\Payroll\Data;

class PayrollCalculationResult
{
    /**
     * @param  list<PayrollProposedResultLine>  $resultLines
     * @param  list<array<string, mixed>>  $warnings
     * @param  list<array<string, mixed>>  $blockingErrors
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public readonly array $resultLines = [],
        public readonly array $warnings = [],
        public readonly array $blockingErrors = [],
        public readonly array $metadata = [],
    ) {}

    public function hasBlockingErrors(): bool
    {
        return $this->blockingErrors !== [];
    }
}
