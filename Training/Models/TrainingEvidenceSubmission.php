<?php

namespace App\Domains\People\Training\Models;

use App\Domains\People\Skills\Models\Concerns\CompanyOwned;
use App\Domains\People\Skills\Models\TenantOwnedModel;

final class TrainingEvidenceSubmission extends TenantOwnedModel
{
    use CompanyOwned;

    protected $table = 'people_training_evidence_submissions';

    protected function casts(): array
    {
        return [
            'certificate_expires_on' => 'immutable_date',
            'submitted_at' => 'immutable_datetime',
        ];
    }
}
