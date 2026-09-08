<?php

namespace App\Domains\People\Training\Models;

use App\Domains\People\Skills\Models\Concerns\CompanyOwned;
use App\Domains\People\Skills\Models\TenantOwnedModel;

/** One company's governed checkpoint offsets, in force from a date onwards. */
final class TrainingEffectivenessCheckpointPolicy extends TenantOwnedModel
{
    use CompanyOwned;

    protected $table = 'people_training_effectiveness_policies';

    protected function casts(): array
    {
        return [
            'day_30_offset' => 'integer',
            'day_60_offset' => 'integer',
            'day_90_offset' => 'integer',
            'effective_from' => 'immutable_date',
        ];
    }
}
