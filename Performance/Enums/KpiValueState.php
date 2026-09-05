<?php

namespace App\Domains\People\Performance\Enums;

enum KpiValueState: string
{
    case Missing = 'missing';
    case Zero = 'zero';
    case ZeroDenominator = 'zero_denominator';
    case Value = 'value';
}
