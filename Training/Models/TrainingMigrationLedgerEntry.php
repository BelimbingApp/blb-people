<?php

namespace App\Domains\People\Training\Models;

use App\Domains\People\Skills\Models\Concerns\CompanyOwned;
use App\Domains\People\Skills\Models\TenantOwnedModel;
use App\Domains\People\Training\Enums\MigrationLedgerStatus;

/** One migrated or quarantined source row and its later reconciliation status. */
final class TrainingMigrationLedgerEntry extends TenantOwnedModel
{
    use CompanyOwned;

    protected $table = 'people_training_migration_ledger';

    protected function casts(): array
    {
        return [
            'source_row' => 'integer',
            'target_id' => 'integer',
            'status' => MigrationLedgerStatus::class,
            'payload_excerpt' => 'array',
            'recorded_by' => 'integer',
            'recorded_at' => 'datetime',
            'reconciled_at' => 'datetime',
        ];
    }
}
