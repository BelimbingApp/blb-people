<?php

namespace App\Domains\People\Training\Models;

use App\Domains\People\Skills\Models\Concerns\CompanyOwned;
use App\Domains\People\Skills\Models\TenantOwnedModel;
use App\Domains\People\Training\Enums\MigrationWorkflow;
use App\Domains\People\Training\Enums\MigrationWriter;
use App\Domains\People\Training\Exceptions\InvalidTrainingMigrationMappingException;

/**
 * Who is the authoritative writer of one workflow between two dates.
 *
 * Append-only: a window that can be edited after the cutover began is a
 * window nobody can rely on having been in force. ends_on null means "until
 * further notice", which is every day after starts_on.
 */
final class TrainingMigrationWriterWindow extends TenantOwnedModel
{
    use CompanyOwned;

    protected $table = 'people_training_migration_writer_windows';

    protected static function booted(): void
    {
        self::updating(function (): void {
            throw new InvalidTrainingMigrationMappingException('A writer window cannot be modified.');
        });

        self::deleting(function (): void {
            throw new InvalidTrainingMigrationMappingException('A writer window cannot be deleted.');
        });
    }

    protected function casts(): array
    {
        return [
            'workflow' => MigrationWorkflow::class,
            'writer' => MigrationWriter::class,
            'starts_on' => 'immutable_date',
            'ends_on' => 'immutable_date',
            'created_by_user_id' => 'integer',
        ];
    }
}
