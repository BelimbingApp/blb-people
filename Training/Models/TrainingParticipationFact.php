<?php

namespace App\Domains\People\Training\Models;

use App\Domains\People\Skills\Models\Concerns\CompanyOwned;
use App\Domains\People\Skills\Models\TenantOwnedModel;
use App\Domains\People\Training\Enums\AttendanceStatus;

final class TrainingParticipationFact extends TenantOwnedModel
{
    use CompanyOwned;

    protected $table = 'people_training_participation_facts';

    protected function casts(): array
    {
        return ['attendance' => AttendanceStatus::class,
            'actual_minutes' => 'integer',
            'pre_test' => 'array',
            'post_test' => 'array',
            'evidence_references' => 'array',
            'certificate_valid_from' => 'immutable_date',
            'certificate_valid_until' => 'immutable_date',
            'recorded_at' => 'immutable_datetime',
            'confirmed_at' => 'immutable_datetime', ];
    }
}
