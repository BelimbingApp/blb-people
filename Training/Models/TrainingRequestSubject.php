<?php

namespace App\Domains\People\Training\Models;

use App\Domains\People\Skills\Models\Concerns\CompanyOwned;
use App\Domains\People\Skills\Models\TenantOwnedModel;

/**
 * One person a training request is for, frozen at draft time.
 */
final class TrainingRequestSubject extends TenantOwnedModel
{
    use CompanyOwned;

    public const SOURCE_INDIVIDUAL = 'individual';

    public const SOURCE_COHORT = 'cohort';

    protected $table = 'people_training_request_subjects';

    protected function casts(): array
    {
        return [
            'training_request_id' => 'integer',
            'workforce_observed_at' => 'immutable_datetime',
        ];
    }
}
