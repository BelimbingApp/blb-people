<?php

namespace App\Domains\People\Training\Enums;

/**
 * The workbook's 30/60/90-day stages plus the Final stage retained from the
 * 0013 scope. These intervals are a company default, not a standards
 * requirement, which is why the due date and the policy that chose it are
 * recorded per review rather than derived from the stage.
 */
enum EffectivenessReviewStage: string
{
    case Day30 = 'day_30';
    case Day60 = 'day_60';
    case Day90 = 'day_90';
    case Final = 'final';

    public function label(): string
    {
        return match ($this) {
            self::Day30 => '30-Day',
            self::Day60 => '60-Day',
            self::Day90 => '90-Day',
            self::Final => 'Final',
        };
    }
}
