<?php

namespace App\Modules\People\Payroll\Data;

class PayrollProposedResultLine
{
    /**
     * @param  array<string, mixed>  $explanation
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public readonly string $lineType,
        public readonly string $code,
        public readonly string $label,
        public readonly string $amount,
        public readonly string $currency,
        public readonly string $sourceRule,
        public readonly string $sourceVersion,
        public readonly ?int $payrollInputId = null,
        public readonly array $explanation = [],
        public readonly array $metadata = [],
    ) {}
}
