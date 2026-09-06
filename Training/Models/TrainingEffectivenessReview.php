<?php

namespace App\Domains\People\Training\Models;

use App\Domains\People\Provider\Enums\WorkforceResourceType;
use App\Domains\People\Skills\Contracts\ReferencesWorkforceEntities;
use App\Domains\People\Skills\Data\WorkforceReference;
use App\Domains\People\Skills\Models\Concerns\CompanyOwned;
use App\Domains\People\Skills\Models\TenantOwnedModel;
use App\Domains\People\Training\Enums\EffectivenessClosureRoute;
use App\Domains\People\Training\Enums\EffectivenessOutcome;
use App\Domains\People\Training\Enums\EffectivenessReviewStage;
use App\Domains\People\Training\Enums\EffectivenessReviewState;

final class TrainingEffectivenessReview extends TenantOwnedModel implements ReferencesWorkforceEntities
{
    use CompanyOwned;

    protected $table = 'people_training_effectiveness_reviews';

    /** @return list<WorkforceReference> */
    public function workforceReferences(): array
    {
        return [new WorkforceReference('reviewer_employee_entity_id', WorkforceResourceType::Employee)];
    }

    protected function casts(): array
    {
        return [
            'stage' => EffectivenessReviewStage::class,
            'state' => EffectivenessReviewState::class,
            'outcome' => EffectivenessOutcome::class,
            'closure_route' => EffectivenessClosureRoute::class,
            'due_on' => 'immutable_date',
            'reviewed_on' => 'immutable_date',
            'baseline_level' => 'integer',
            'target_level' => 'integer',
            'post_level' => 'integer',
            'application_rating' => 'integer',
            'improvement_rating' => 'integer',
            'impact_rating' => 'integer',
            'requirement_version' => 'integer',
            'reassessment_requirement_version' => 'integer',
            'outcome_recorded_at' => 'immutable_datetime',
            'closed_at' => 'immutable_datetime',
        ];
    }

    public function getAuditSubject(): ?array
    {
        return ['name' => 'training_effectiveness_review', 'id' => $this->getKey()];
    }
}
