<?php

namespace App\Domains\People\Organisation\Enums;

/**
 * A position may be held substantively by at most one person at a time, but
 * acting cover and concurrent appointments are ordinary facts, not conflicts.
 * Keeping them distinct is what lets a transfer, a vacancy and an acting spell
 * resolve to different answers instead of one ambiguous "current holder".
 */
enum PositionAssignmentType: string
{
    case Substantive = 'substantive';
    case Acting = 'acting';
    case Concurrent = 'concurrent';

    public function label(): string
    {
        return match ($this) {
            self::Substantive => 'Substantive',
            self::Acting => 'Acting',
            self::Concurrent => 'Concurrent',
        };
    }
}
