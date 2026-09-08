<?php

namespace App\Domains\People\Training\Models;

use App\Domains\People\Skills\Models\Concerns\CompanyOwned;
use App\Domains\People\Skills\Models\TenantOwnedModel;
use App\Domains\People\Training\Exceptions\InvalidTrainingMigrationSourceException;

/**
 * That HR signed a migration source as inventoried. Append-only: a sign-off
 * that can be edited or removed is not a signature.
 */
final class TrainingMigrationSourceSignoff extends TenantOwnedModel
{
    use CompanyOwned;

    protected $table = 'people_training_migration_source_signoffs';

    protected static function booted(): void
    {
        self::updating(function (): void {
            throw new InvalidTrainingMigrationSourceException('A migration source sign-off cannot be modified.');
        });

        self::deleting(function (): void {
            throw new InvalidTrainingMigrationSourceException('A migration source sign-off cannot be deleted.');
        });
    }

    protected function casts(): array
    {
        return [
            'training_migration_source_id' => 'integer',
            'signed_by_user_id' => 'integer',
            'signed_at' => 'immutable_datetime',
        ];
    }
}
