<?php

namespace App\Domains\People\Training\Services;

use App\Base\Tenancy\Contracts\TenantContext;
use App\Core\User\Models\User;
use App\Domains\People\Skills\Services\CompanyAttribution;
use App\Domains\People\Skills\Services\SkillAudience;
use App\Domains\People\Training\Data\TrainingPlanDraft;
use App\Domains\People\Training\Data\TrainingPlanItemDraft;
use App\Domains\People\Training\Enums\TrainingPlanStatus;
use App\Domains\People\Training\Exceptions\InvalidTrainingPlanException;
use App\Domains\People\Training\Models\TrainingPlan;
use App\Domains\People\Training\Models\TrainingPlanItem;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class TrainingPlanStore
{
    public const SUBMIT_CAPABILITY = 'people.training.plan.submit';

    public const APPROVE_CAPABILITY = 'people.training.plan.approve';

    public function __construct(
        private readonly TenantContext $tenancy,
        private readonly CompanyAttribution $companies,
        private readonly SkillAudience $audiences,
    ) {}

    public function createDraft(User $actor, int $companyId, TrainingPlanDraft $draft): TrainingPlan
    {
        $tenant = $this->scope($actor, $companyId);
        $this->authorizeHod($actor, $companyId, $draft->departmentEntityId);
        $this->validateDraft($tenant, $companyId, $draft);

        return DB::transaction(function () use ($actor, $companyId, $draft, $tenant): TrainingPlan {
            $plan = TrainingPlan::query()->create([
                'tenant_id' => $tenant,
                'company_entity_id' => $companyId,
                'plan_key' => (string) Str::uuid(),
                'version' => 1,
                'department_entity_id' => $draft->departmentEntityId,
                'period_start' => $draft->periodStart,
                'period_end' => $draft->periodEnd,
                'objectives' => trim($draft->objectives),
                'financial_tracking_enabled' => $draft->financialTrackingEnabled,
                'status' => TrainingPlanStatus::Draft,
                'accountable_owner_user_id' => $actor->getKey(),
            ]);
            $this->writeItems($plan, $draft->items);

            return $plan;
        });
    }

    public function submit(User $actor, int $companyId, int $planId): TrainingPlan
    {
        $tenant = $this->scope($actor, $companyId);

        return DB::transaction(function () use ($actor, $companyId, $planId, $tenant): TrainingPlan {
            $plan = $this->find($tenant, $companyId, $planId);
            $this->authorizeHod($actor, $companyId, (int) $plan->department_entity_id);
            if ($plan->status !== TrainingPlanStatus::Draft) {
                throw new InvalidTrainingPlanException('Only a draft training plan revision can be submitted.');
            }
            $plan->update([
                'status' => TrainingPlanStatus::Submitted,
                'submitted_by_user_id' => $actor->getKey(),
                'submitted_at' => now(),
            ]);

            return $plan->refresh();
        });
    }

    public function approve(User $actor, int $companyId, int $planId): TrainingPlan
    {
        $tenant = $this->scope($actor, $companyId);
        $this->authorizeHr($actor, $companyId);

        return DB::transaction(function () use ($actor, $companyId, $planId, $tenant): TrainingPlan {
            $plan = $this->find($tenant, $companyId, $planId);
            if ($plan->status !== TrainingPlanStatus::Submitted) {
                throw new InvalidTrainingPlanException('Only a submitted training plan revision can be approved.');
            }
            $status = $plan->amends_plan_id === null ? TrainingPlanStatus::Approved : TrainingPlanStatus::Amended;
            $plan->update([
                'status' => $status,
                'approved_by_user_id' => $actor->getKey(),
                'approved_at' => now(),
            ]);
            if ($plan->amends_plan_id !== null) {
                $prior = $this->find($tenant, $companyId, (int) $plan->amends_plan_id);
                $prior->update(['status' => TrainingPlanStatus::Superseded]);
            }

            return $plan->refresh();
        });
    }

    public function amend(User $actor, int $companyId, int $planId, string $reason): TrainingPlan
    {
        $tenant = $this->scope($actor, $companyId);

        return DB::transaction(function () use ($actor, $companyId, $planId, $reason, $tenant): TrainingPlan {
            $prior = $this->find($tenant, $companyId, $planId);
            $this->authorizeHod($actor, $companyId, (int) $prior->department_entity_id);
            if (! in_array($prior->status, [TrainingPlanStatus::Approved, TrainingPlanStatus::Amended], true)
                || trim($reason) === '') {
                throw new InvalidTrainingPlanException('Only approved plan scope can begin a reasoned amendment.');
            }
            $plan = TrainingPlan::query()->create([
                'tenant_id' => $tenant,
                'company_entity_id' => $companyId,
                'plan_key' => $prior->plan_key,
                'version' => (int) TrainingPlan::query()->forCompany($tenant, $companyId)
                    ->where('plan_key', $prior->plan_key)->max('version') + 1,
                'department_entity_id' => $prior->department_entity_id,
                'period_start' => $prior->period_start,
                'period_end' => $prior->period_end,
                'objectives' => $prior->objectives,
                'financial_tracking_enabled' => $prior->financial_tracking_enabled,
                'status' => TrainingPlanStatus::Draft,
                'accountable_owner_user_id' => $actor->getKey(),
                'amends_plan_id' => $prior->id,
                'amendment_reason' => trim($reason),
            ]);
            $items = $prior->items()->get()->map(fn (TrainingPlanItem $item): TrainingPlanItemDraft => new TrainingPlanItemDraft(
                $tenant, $companyId, $item->need_reference, $item->expected_result,
                $item->target_cohort, $item->delivery_approach, $item->responsible_owner_reference,
                $item->intended_timing, $item->evaluation_approach, $item->budget_line,
            ))->all();
            $this->writeItems($plan, $items);

            return $plan;
        });
    }

    private function scope(User $actor, int $companyId): int
    {
        $tenant = $this->tenancy->currentTenantId();
        if ($tenant === null) {
            throw new InvalidTrainingPlanException('A tenant context is required for training plans.');
        }
        if ((int) $actor->tenant_id !== $tenant || ! $this->companies->mayActFor($actor, $companyId)) {
            throw new InvalidTrainingPlanException('The training plan is unavailable in the current company scope.');
        }

        return $tenant;
    }

    private function authorizeHod(User $actor, int $companyId, int $departmentId): void
    {
        if (! in_array(SkillAudience::HOD, $this->audiences->authorizeAudience(
            $actor, self::SUBMIT_CAPABILITY,
        ), true)) {
            throw new InvalidTrainingPlanException('Only a HOD may submit a departmental training plan.');
        }
        if (! in_array($departmentId, $this->audiences->visibleOrganizationUnitEntityIds(
            $actor, $companyId, self::SUBMIT_CAPABILITY,
        ), true)) {
            throw new InvalidTrainingPlanException('The department is outside the HOD planning scope.');
        }
    }

    private function authorizeHr(User $actor, int $companyId): void
    {
        if (! in_array(SkillAudience::HR, $this->audiences->authorizeAudience(
            $actor, self::APPROVE_CAPABILITY,
        ), true) || ! $this->companies->mayActFor($actor, $companyId)) {
            throw new InvalidTrainingPlanException('Only HR may approve a company training plan.');
        }
    }

    private function validateDraft(int $tenant, int $companyId, TrainingPlanDraft $draft): void
    {
        if (CarbonImmutable::instance($draft->periodEnd)->lessThan(CarbonImmutable::instance($draft->periodStart))
            || trim($draft->objectives) === '' || $draft->items === []) {
            throw new InvalidTrainingPlanException('A plan needs a valid period, objectives, and at least one item.');
        }
        foreach ($draft->items as $item) {
            if (! $item instanceof TrainingPlanItemDraft
                || $item->needTenantId !== $tenant || $item->needCompanyEntityId !== $companyId) {
                throw new InvalidTrainingPlanException('Every item need must belong to the same tenant and company.');
            }
            foreach ([$item->needReference, $item->expectedResult, $item->targetCohort,
                $item->responsibleOwnerReference, $item->intendedTiming, $item->evaluationApproach] as $value) {
                if (trim($value) === '') {
                    throw new InvalidTrainingPlanException('Training plan item fields cannot be empty.');
                }
            }
            if ($item->budgetLine !== null && ! $draft->financialTrackingEnabled) {
                throw new InvalidTrainingPlanException('A budget line requires financial tracking to be enabled.');
            }
        }
    }

    /** @param list<TrainingPlanItemDraft> $items */
    private function writeItems(TrainingPlan $plan, array $items): void
    {
        foreach ($items as $item) {
            TrainingPlanItem::query()->forCompany((int) $plan->tenant_id, (int) $plan->company_entity_id)->create([
                'tenant_id' => $plan->tenant_id,
                'company_entity_id' => $plan->company_entity_id,
                'training_plan_id' => $plan->id,
                'need_reference' => trim($item->needReference),
                'expected_result' => trim($item->expectedResult),
                'target_cohort' => trim($item->targetCohort),
                'delivery_approach' => $item->deliveryApproach,
                'responsible_owner_reference' => trim($item->responsibleOwnerReference),
                'intended_timing' => trim($item->intendedTiming),
                'evaluation_approach' => trim($item->evaluationApproach),
                'budget_line' => $item->budgetLine,
            ]);
        }
    }

    private function find(int $tenant, int $companyId, int $planId): TrainingPlan
    {
        return TrainingPlan::query()->forCompany($tenant, $companyId)->lockForUpdate()->find($planId)
            ?? throw new InvalidTrainingPlanException('Training plan was not found in this company.');
    }
}
