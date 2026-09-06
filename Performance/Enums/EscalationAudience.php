<?php

namespace App\Domains\People\Performance\Enums;

/** Who reads an escalation when the manager has not acted. */
enum EscalationAudience: string
{
    case Manager = 'manager';
    case Hr = 'hr';

    public function label(): string
    {
        return match ($this) {
            self::Manager => "The manager's manager",
            self::Hr => 'HR',
        };
    }
}
