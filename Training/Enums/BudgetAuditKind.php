<?php

namespace App\Domains\People\Training\Enums;

/**
 * What a budget audit row records.
 *
 * One table answers "what happened to this budget", but the two things that
 * happen to it are not the same: an allocation moves the amount, an override
 * spends past it while the amount stays exactly where it was.
 */
enum BudgetAuditKind: string
{
    case Allocation = 'allocation';
    case Override = 'override';

    public function label(): string
    {
        return match ($this) {
            self::Allocation => 'Allocation changed',
            self::Override => 'Approved over budget',
        };
    }
}
