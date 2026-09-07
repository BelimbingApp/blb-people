<?php

namespace App\Domains\People\Training\Enums;

/** Where a legacy skill or training record lives before it is imported (0015-a). */
enum MigrationSourceKind: string
{
    case Portal = 'portal';
    case Workbook = 'workbook';
    case Departmental = 'departmental';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Portal => 'Legacy portal',
            self::Workbook => 'Workbook',
            self::Departmental => 'Departmental records',
            self::Other => 'Other',
        };
    }
}
