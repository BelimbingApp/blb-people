<?php

namespace App\Domains\People\Training\Enums;

/** Which system is allowed to write a workflow during a cutover window (0015-b). */
enum MigrationWriter: string
{
    case Legacy = 'legacy';
    case People = 'people';

    public function label(): string
    {
        return match ($this) {
            self::Legacy => 'Legacy portal',
            self::People => 'People',
        };
    }
}
