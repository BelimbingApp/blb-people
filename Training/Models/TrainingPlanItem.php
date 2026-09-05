<?php

namespace App\Domains\People\Training\Models;

use App\Domains\People\Skills\Models\Concerns\CompanyOwned;
use App\Domains\People\Skills\Models\TenantOwnedModel;
use App\Domains\People\Training\Enums\TrainingDeliveryApproach;
use App\Domains\People\Training\Enums\TrainingPlanStatus;
use App\Domains\People\Training\Exceptions\InvalidTrainingPlanException;

final class TrainingPlanItem extends TenantOwnedModel
{
    use CompanyOwned;

    protected $table = 'people_training_plan_items';

    protected static function booted(): void
    {
        $guard = function (self $item): void {
            $plan = TrainingPlan::query()
                ->forCompany((int) $item->tenant_id, (int) $item->company_entity_id)
                ->find($item->training_plan_id);
            if ($plan === null || $plan->status !== TrainingPlanStatus::Draft) {
                throw new InvalidTrainingPlanException('Items are immutable after their plan revision is submitted.');
            }
        };
        self::creating($guard);
        self::updating($guard);
        self::deleting($guard);
    }

    protected function casts(): array
    {
        return [
            'delivery_approach' => TrainingDeliveryApproach::class,
            'budget_line' => 'array',
        ];
    }
}
