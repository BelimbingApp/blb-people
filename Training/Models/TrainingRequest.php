<?php

namespace App\Domains\People\Training\Models;

use App\Domains\People\Skills\Models\Concerns\CompanyOwned;
use App\Domains\People\Skills\Models\TenantOwnedModel;
use App\Domains\People\Training\Enums\TrainingNeedSource;
use App\Domains\People\Training\Enums\TrainingPriority;
use App\Domains\People\Training\Enums\TrainingRequestStatus;
use App\Domains\People\Training\Exceptions\InvalidTrainingRequestException;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class TrainingRequest extends TenantOwnedModel
{
    use CompanyOwned;

    protected $table = 'people_training_requests';

    /**
     * Set only through reviseFacts(): the one sanctioned rewrite of request
     * facts (a rejected request's new draft substance). Everything else that
     * dirties a fact is still refused.
     */
    public bool $allowFactsRevision = false;

    protected static function booted(): void
    {
        self::updating(function (self $request): void {
            $facts = ['tenant_id', 'company_entity_id', 'request_key', 'requestor_provider_id',
                'requestor_subject_id', 'department_provider_id', 'department_subject_id', 'need_source',
                'need', 'learning_objective', 'expected_result', 'priority', 'skill_gap_assessment_id',
                'requirement_version', 'estimated_cost', 'proposed_delivery_method', 'proposed_provider',
                'proposed_start_date', 'proposed_end_date', 'approved_budget', 'created_by_user_id'];
            if (! $request->allowFactsRevision && $request->isDirty($facts)) {
                throw new InvalidTrainingRequestException('Training request facts are immutable.');
            }
        });
    }

    /**
     * Replace the draft substance on revision. The key, requestor, department
     * and author are not fillable here by construction: callers pass only
     * the substance columns, so a revision can never smuggle an identity
     * change through this door.
     *
     * @param  array{need_source: TrainingNeedSource, need: string, learning_objective: string, expected_result: string, priority: TrainingPriority, estimated_cost: ?string, proposed_delivery_method: ?string, proposed_provider: ?string, proposed_start_date: ?string, proposed_end_date: ?string, skill_gap_assessment_id: ?int, requirement_version: ?int}  $substance
     */
    public function reviseFacts(array $substance): void
    {
        $this->allowFactsRevision = true;
        try {
            $this->fill($substance);
            $this->save();
        } finally {
            $this->allowFactsRevision = false;
        }
    }

    /** Record the decision-time amount without opening ordinary fact mutation. */
    public function recordApprovedBudget(?string $amount): void
    {
        $this->allowFactsRevision = true;
        try {
            $this->approved_budget = $amount;
            $this->save();
        } finally {
            $this->allowFactsRevision = false;
        }
    }

    public function decisions(): HasMany
    {
        return $this->hasMany(TrainingRequestDecision::class, 'training_request_id')
            ->forCompany((int) $this->tenant_id, (int) $this->company_entity_id)->orderBy('id');
    }

    protected function casts(): array
    {
        return ['need_source' => TrainingNeedSource::class, 'priority' => TrainingPriority::class,
            'status' => TrainingRequestStatus::class, 'requirement_version' => 'integer',
            'training_event_id' => 'integer', 'linked_by_user_id' => 'integer', 'linked_at' => 'immutable_datetime',
            'estimated_cost' => 'decimal:4', 'approved_budget' => 'decimal:4',
            'proposed_start_date' => 'immutable_date', 'proposed_end_date' => 'immutable_date'];
    }
}
