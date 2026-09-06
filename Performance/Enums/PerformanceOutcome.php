<?php

namespace App\Domains\People\Performance\Enums;

enum PerformanceOutcome: string
{
    case NotMet = 'not_met';
    case PartiallyMet = 'partially_met';
    case Met = 'met';
    case Exceeded = 'exceeded';

    public function label(): string
    {
        return match ($this) {
            self::NotMet => 'Not met',
            self::PartiallyMet => 'Partially met',
            self::Met => 'Met',
            self::Exceeded => 'Exceeded',
        };
    }
}
