<?php

namespace App\Domains\People\Training\Models;

use App\Domains\People\Skills\Models\Concerns\CompanyOwned;
use App\Domains\People\Skills\Models\TenantOwnedModel;

final class TrainingEvidenceDecision extends TenantOwnedModel
{
    use CompanyOwned;

    protected $table = 'people_training_evidence_decisions';

    protected function casts(): array
    {
        return [
            'decided_at' => 'immutable_datetime',
        ];
    }
}
