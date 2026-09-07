<?php

namespace App\Domains\People\Training\Models;

use App\Domains\People\Skills\Models\Concerns\CompanyOwned;
use App\Domains\People\Skills\Models\TenantOwnedModel;
use App\Domains\People\Training\Enums\MigrationSourceKind;
use Illuminate\Database\Eloquent\Relations\HasOne;

/** One legacy source HR intends to import from: who owns it, what shape it is, how much there is. */
final class TrainingMigrationSource extends TenantOwnedModel
{
    use CompanyOwned;

    protected $table = 'people_training_migration_sources';

    public function signoff(): HasOne
    {
        return $this->hasOne(TrainingMigrationSourceSignoff::class, 'training_migration_source_id')
            ->forCompany((int) $this->tenant_id, (int) $this->company_entity_id);
    }

    protected function casts(): array
    {
        return [
            'kind' => MigrationSourceKind::class,
            'owner_employee_id' => 'integer',
            'estimated_volume' => 'integer',
            'created_by_user_id' => 'integer',
        ];
    }
}
