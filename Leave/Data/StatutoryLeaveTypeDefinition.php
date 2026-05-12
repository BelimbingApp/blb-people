<?php

namespace App\Modules\People\Leave\Data;

class StatutoryLeaveTypeDefinition
{
    /**
     * @param  array<string, mixed>  $eligibilityPredicate  Optional demographic predicate (e.g. ['gender' => 'female']).
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public readonly string $code,
        public readonly string $name,
        public readonly bool $paid,
        public readonly string $defaultUnit,
        public readonly bool $interactsWithPayroll = false,
        public readonly bool $compulsoryAttachment = false,
        public readonly ?string $payrollPayItemCode = null,
        public readonly ?string $auditTag = null,
        public readonly ?int $hourQuantumMinutes = null,
        public readonly array $eligibilityPredicate = [],
        public readonly array $metadata = [],
    ) {}
}
