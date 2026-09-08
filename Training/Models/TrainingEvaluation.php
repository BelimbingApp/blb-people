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

    /** The participant typed their own answers. */
    public const ENTRY_SELF = 'self';

    /**
     * HR keyed in a completed paper form on the participant's behalf (0012-f).
     * submitted_by_user_id is then the HR user, never the participant, and the
     * database refuses this source without an entering actor.
     */
    public const ENTRY_ASSISTED_PAPER = 'assisted_paper';

    public function enteredFromPaper(): bool
    {
        return $this->entry_source === self::ENTRY_ASSISTED_PAPER;
    }

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
