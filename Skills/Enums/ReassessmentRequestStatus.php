<?php

namespace App\Domains\People\Skills\Enums;

enum ReassessmentRequestStatus: string
{
    case Pending = 'pending';
    case Resolved = 'resolved';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Resolved => 'Resolved',
            self::Cancelled => 'Cancelled',
        };
    }

    public function isOpen(): bool
    {
        return $this === self::Pending;
    }
}
