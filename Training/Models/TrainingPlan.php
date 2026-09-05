<?php

namespace App\Domains\People\Training\Models;

use App\Domains\People\Provider\Enums\WorkforceResourceType;
use App\Domains\People\Skills\Contracts\ReferencesWorkforceEntities;
use App\Domains\People\Skills\Data\WorkforceReference;
use App\Domains\People\Skills\Models\Concerns\CompanyOwned;
use App\Domains\People\Skills\Models\TenantOwnedModel;
use App\Domains\People\Training\Enums\TrainingPlanStatus;
use App\Domains\People\Training\Exceptions\InvalidTrainingPlanException;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class TrainingPlan extends TenantOwnedModel implements ReferencesWorkforceEntities
{
    use CompanyOwned;

    protected $table = 'people_training_plans';

    protected static function booted(): void
    {
        self::updating(function (self $plan): void {
            $identity = ['tenant_id', 'company_entity_id', 'plan_key', 'version', 'amends_plan_id'];
            $content = ['department_entity_id', 'period_start', 'period_end', 'objectives', 'financial_tracking_enabled'];
            if ($plan->isDirty($identity)
                || ($plan->getOriginal('status') !== TrainingPlanStatus::Draft->value && $plan->isDirty($content))) {
                throw new InvalidTrainingPlanException('Submitted training plan identity and content are immutable.');
            }
        });

        self::deleting(function (self $plan): void {
            if ($plan->status !== TrainingPlanStatus::Draft) {
                throw new InvalidTrainingPlanException('Submitted training plan revisions cannot be deleted.');
            }
        });
    }

    public function items(): HasMany
    {
        return $this->hasMany(TrainingPlanItem::class, 'training_plan_id')
            ->forCompany((int) $this->tenant_id, (int) $this->company_entity_id);
    }

    /** @return list<WorkforceReference> */
    public function workforceReferences(): array
    {
        return [new WorkforceReference('department_entity_id', WorkforceResourceType::OrganizationUnit)];
    }

    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'period_start' => 'immutable_date',
            'period_end' => 'immutable_date',
            'financial_tracking_enabled' => 'boolean',
            'status' => TrainingPlanStatus::class,
            'submitted_at' => 'immutable_datetime',
            'approved_at' => 'immutable_datetime',
        ];
    }
}
