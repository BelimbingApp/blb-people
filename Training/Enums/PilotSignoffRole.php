<?php

namespace App\Domains\People\Training\Enums;

enum PilotSignoffRole: string
{
    case Hod = 'hod';
    case Hr = 'hr';

    public function label(): string
    {
        return match ($this) {
            self::Hod => 'Head of department',
            self::Hr => 'HR',
        };
    }
}
