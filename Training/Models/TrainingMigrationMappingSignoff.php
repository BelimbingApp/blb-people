<?php

namespace App\Domains\People\Training\Models;

use App\Domains\People\Skills\Models\Concerns\CompanyOwned;
use App\Domains\People\Skills\Models\TenantOwnedModel;
use App\Domains\People\Training\Exceptions\InvalidTrainingMigrationMappingException;

/**
 * That HR signed a company's mapping set and writer windows as final. One
 * row per company, append-only: from this row on, nothing is appended to
 * either register.
 */
final class TrainingMigrationMappingSignoff extends TenantOwnedModel
{
    use CompanyOwned;

    protected $table = 'people_training_migration_mapping_signoffs';

    protected static function booted(): void
    {
        self::updating(function (): void {
            throw new InvalidTrainingMigrationMappingException('A mapping set sign-off cannot be modified.');
        });

        self::deleting(function (): void {
            throw new InvalidTrainingMigrationMappingException('A mapping set sign-off cannot be deleted.');
        });
    }

    protected function casts(): array
    {
        return [
            'signed_by_user_id' => 'integer',
            'signed_at' => 'immutable_datetime',
        ];
    }
}
