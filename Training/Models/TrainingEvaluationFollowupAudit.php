<?php

namespace App\Domains\People\Training\Models;

use App\Domains\People\Skills\Models\Concerns\CompanyOwned;
use App\Domains\People\Skills\Models\TenantOwnedModel;
use App\Domains\People\Training\Exceptions\InvalidTrainingEvaluationException;

/**
 * One follow-up transition: who moved it, to what, and what was done.
 *
 * Append-only like the other domain audits: a follow-up that could have its
 * history revised after the fact would not be a record of what HR did.
 */
final class TrainingEvaluationFollowupAudit extends TenantOwnedModel
{
    use CompanyOwned;

    protected $table = 'people_training_evaluation_followup_audits';

    public function companyOwnerColumn(): ?string
    {
        return 'company_entity_id';
    }

    protected function casts(): array
    {
        return [
            'occurred_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        $immutable = fn (): never => throw new InvalidTrainingEvaluationException(
            'A training evaluation follow-up audit record cannot be modified.',
        );

        self::updating($immutable);
        self::deleting($immutable);
    }
}
