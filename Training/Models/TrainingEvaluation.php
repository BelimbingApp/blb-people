<?php

namespace App\Domains\People\Training\Models;

use App\Domains\People\Skills\Models\Concerns\CompanyOwned;
use App\Domains\People\Skills\Models\TenantOwnedModel;
use App\Domains\People\Training\Enums\TrainingEvaluationStatus;

/**
 * One participant's evaluation of one training event.
 *
 * The criteria version is a column rather than a lookup: an older completed
 * evaluation must stay reproducible when the form moves on.
 */
final class TrainingEvaluation extends TenantOwnedModel
{
    use CompanyOwned;

    protected $table = 'people_training_evaluations';

    public function companyOwnerColumn(): ?string
    {
        return 'company_entity_id';
    }

    protected function casts(): array
    {
        return [
            'status' => TrainingEvaluationStatus::class,
            'due_on' => 'date',
            'completed_at' => 'immutable_datetime',
        ];
    }
}
