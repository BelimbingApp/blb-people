<?php

namespace App\Domains\People\Training\Models;

use App\Domains\People\Skills\Models\Concerns\CompanyOwned;
use App\Domains\People\Skills\Models\TenantOwnedModel;
use App\Domains\People\Training\Exceptions\InvalidTrainingMigrationMappingException;

/**
 * One legacy field (and optionally one code value) and the People column it
 * lands in, with the rule that decides between overlapping records.
 *
 * Append-only: a correction is a new row, so the register the importer read
 * is the register HR signed. Nothing is appended after the sign-off.
 */
final class TrainingMigrationFieldMapping extends TenantOwnedModel
{
    use CompanyOwned;

    protected $table = 'people_training_migration_field_mappings';

    protected static function booted(): void
    {
        self::updating(function (): void {
            throw new InvalidTrainingMigrationMappingException('A migration field mapping cannot be modified.');
        });

        self::deleting(function (): void {
            throw new InvalidTrainingMigrationMappingException('A migration field mapping cannot be deleted.');
        });
    }

    protected function casts(): array
    {
        return [
            'training_migration_source_id' => 'integer',
            'created_by_user_id' => 'integer',
        ];
    }
}
