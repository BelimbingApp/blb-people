<?php

namespace App\Domains\People\Training\Models;

use App\Domains\People\Skills\Models\Concerns\CompanyOwned;
use App\Domains\People\Skills\Models\TenantOwnedModel;
use App\Domains\People\Training\Enums\TrainingEvaluationFollowupKind;
use App\Domains\People\Training\Enums\TrainingEvaluationFollowupStatus;

/**
 * HR's follow-up on one evaluation's support request or provider concern.
 *
 * A standalone record on purpose: the evaluation row keeps the participant's
 * answers exactly as submitted, and this row keeps what HR did about them.
 */
final class TrainingEvaluationFollowup extends TenantOwnedModel
{
    use CompanyOwned;

    protected $table = 'people_training_evaluation_followups';

    public function companyOwnerColumn(): ?string
    {
        return 'company_entity_id';
    }

    protected function casts(): array
    {
        return [
            'kind' => TrainingEvaluationFollowupKind::class,
            'status' => TrainingEvaluationFollowupStatus::class,
            'closed_at' => 'immutable_datetime',
        ];
    }
}
