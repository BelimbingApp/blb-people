<?php

namespace App\Domains\People\Training\Models;

use App\Domains\People\Skills\Models\Concerns\CompanyOwned;
use App\Domains\People\Skills\Models\TenantOwnedModel;

final class TrainingParticipant extends TenantOwnedModel
{
    use CompanyOwned;

    protected $table = 'people_training_participants';

    protected function casts(): array
    {
        return ['workforce_observed_at' => 'immutable_datetime'];
    }
}
