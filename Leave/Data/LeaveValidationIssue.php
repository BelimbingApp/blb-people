<?php

namespace App\Modules\People\Leave\Data;

class LeaveValidationIssue
{
    public const SEVERITY_BLOCKING = 'blocking';

    public const SEVERITY_WARNING = 'warning';

    /** @param array<string, mixed> $explanation */
    public function __construct(
        public readonly string $code,
        public readonly string $message,
        public readonly string $severity = self::SEVERITY_BLOCKING,
        public readonly array $explanation = [],
    ) {}

    public function isBlocking(): bool
    {
        return $this->severity === self::SEVERITY_BLOCKING;
    }
}
