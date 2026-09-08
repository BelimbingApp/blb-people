<?php

namespace App\Domains\People\Training\Enums;

/** Lifecycle of one migration ledger row (0015-d). */
enum MigrationLedgerStatus: string
{
    case Migrated = 'migrated';
    case Rejected = 'rejected';
    case Reconciled = 'reconciled';
    case Drifted = 'drifted';

    public function label(): string
    {
        return match ($this) {
            self::Migrated => 'Migrated',
            self::Rejected => 'Rejected',
            self::Reconciled => 'Reconciled',
            self::Drifted => 'Drifted',
        };
    }
}
