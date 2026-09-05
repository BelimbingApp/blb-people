<?php

namespace App\Domains\People\Performance\Data;

use App\Domains\People\Performance\Enums\KpiValueState;
use App\Domains\People\Performance\Exceptions\KpiRecordException;

final readonly class KpiResultValue
{
    public function __construct(public KpiValueState $state, public ?float $value = null)
    {
        if (($state === KpiValueState::Value && ($value === null || $value === 0.0 || ! is_finite($value)))
            || ($state === KpiValueState::Zero && $value !== 0.0)
            || (in_array($state, [KpiValueState::Missing, KpiValueState::ZeroDenominator], true) && $value !== null)) {
            throw new KpiRecordException('The KPI value does not match its declared state.');
        }
    }
}
