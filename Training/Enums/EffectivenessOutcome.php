<?php

namespace App\Domains\People\Training\Enums;

enum EffectivenessOutcome: string
{
    case Effective = 'effective';
    case PartiallyEffective = 'partially_effective';
    case NotYetEffective = 'not_yet_effective';
    case NotApplicable = 'not_applicable';

    public function label(): string
    {
        return match ($this) {
            self::Effective => 'Effective',
            self::PartiallyEffective => 'Partially Effective',
            self::NotYetEffective => 'Not Yet Effective',
            self::NotApplicable => 'Not Applicable',
        };
    }
}
