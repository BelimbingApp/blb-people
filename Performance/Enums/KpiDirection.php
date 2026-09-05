<?php

namespace App\Domains\People\Performance\Enums;

enum KpiDirection: string
{
    case HigherIsBetter = 'higher_is_better';
    case LowerIsBetter = 'lower_is_better';
    case Range = 'range';
    case Rubric = 'rubric';
}
