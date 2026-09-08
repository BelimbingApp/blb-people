<?php

namespace App\Domains\People\Training\Enums;

/** Who may write a workflow during a declared cutover window (0015-f). */
enum CutoverWriter: string
{
    case Legacy = 'legacy';
    case System = 'system';

    public function label(): string
    {
        return match ($this) {
            self::Legacy => 'Legacy portal',
            self::System => 'This system',
        };
    }
}
