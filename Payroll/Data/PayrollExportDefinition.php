<?php

namespace App\Modules\People\Payroll\Data;

class PayrollExportDefinition
{
    /**
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly string $frequency,
        public readonly string $format,
        public readonly array $metadata = [],
    ) {}
}
