<?php

namespace App\Domains\People\Training\Services;

use App\Base\Tenancy\Contracts\TenantContext;
use App\Domains\People\Training\Data\TrainingEventDraft;
use App\Domains\People\Training\Enums\TrainingEventStatus;
use App\Domains\People\Training\Enums\TrainingPlanStatus;
use App\Domains\People\Training\Exceptions\InvalidTrainingPlanExecutionException;
use App\Domains\People\Training\Models\TrainingEvent;
use App\Domains\People\Training\Models\TrainingPlan;
use App\Domains\People\Training\Models\TrainingPlanItem;
use Illuminate\Support\Facades\DB;

/**
 * Turns an approved training plan item into a scheduled training event, once.
 *
 * A plan item does not know a course, a date or a room, so this service does
 * not invent them: the operator supplies the event draft. What it owns is the
 * once-only rule and the provenance, both keyed on the item's stable key so
 * that an amendment which restates the same need does not schedule a second
 * event for it.
 */
final class TrainingPlanExecution
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly TrainingEventStore $events,
    ) {}

    public function execute(
        int $companyEntityId,
        int $planItemId,
        TrainingEventDraft $draft,
        ?int $actorUserId = null,
        ?int $actorEmployeeEntityId = null,
    ): TrainingEvent {
        return DB::transaction(function () use ($companyEntityId, $planItemId, $draft, $actorUserId, $actorEmployeeEntityId): TrainingEvent {
            $tenantId = $this->tenantContext->requireTenantId();
            $item = TrainingPlanItem::query()->forCompany($tenantId, $companyEntityId)
                ->lockForUpdate()->find($planItemId)
                ?? throw new InvalidTrainingPlanExecutionException('The training plan item was not found in this company.');
            $plan = TrainingPlan::query()->forCompany($tenantId, $companyEntityId)->find($item->training_plan_id)
                ?? throw new InvalidTrainingPlanExecutionException('The training plan item has no plan revision.');
            if (! in_array($plan->status, [TrainingPlanStatus::Approved, TrainingPlanStatus::Amended], true)) {
                throw new InvalidTrainingPlanExecutionException(
                    'Only an item of an approved plan revision can be executed into a training event.',
                );
            }

            $existing = $this->eventFor($tenantId, $companyEntityId, (string) $item->item_key);
            if ($existing !== null) {
                return $existing;
            }

            $event = $this->events->schedule($companyEntityId, $draft, $actorUserId, $actorEmployeeEntityId);
            $event->update([
                'plan_id' => $plan->id,
                'plan_version' => $plan->version,
                'plan_item_id' => $item->id,
                'plan_item_key' => $item->item_key,
            ]);

            return $event->refresh();
        });
    }

    /**
     * An item is cancelled by leaving it out of the next revision, so the
     * events to reconcile are the ones whose key this plan lineage no longer
     * carries. Anything already under way keeps running: cancelling training
     * people are sitting in is a decision for the organizer, not a side effect
     * of an approval.
     */
    public function cancelDroppedItemEvents(
        int $companyEntityId,
        int $planId,
        ?int $actorUserId = null,
        ?int $actorEmployeeEntityId = null,
    ): int {
        $tenantId = $this->tenantContext->requireTenantId();
        $plan = TrainingPlan::query()->forCompany($tenantId, $companyEntityId)->find($planId)
            ?? throw new InvalidTrainingPlanExecutionException('The training plan revision was not found in this company.');

        $lineage = TrainingPlan::query()->forCompany($tenantId, $companyEntityId)
            ->where('plan_key', $plan->plan_key)->pluck('id');
        $current = TrainingPlanItem::query()->forCompany($tenantId, $companyEntityId)
            ->where('training_plan_id', $plan->id)->pluck('item_key');

        $dropped = TrainingEvent::query()->forCompany($tenantId, $companyEntityId)
            ->whereIn('plan_id', $lineage)
            ->whereNotNull('plan_item_key')
            ->whereNotIn('plan_item_key', $current)
            ->where('status', TrainingEventStatus::Scheduled)
            ->pluck('id');

        foreach ($dropped as $eventId) {
            $this->events->cancel(
                $companyEntityId, (int) $eventId,
                'The plan item was dropped by an approved amendment.',
                $actorUserId, $actorEmployeeEntityId,
            );
        }

        return $dropped->count();
    }

    private function eventFor(int $tenantId, int $companyEntityId, string $itemKey): ?TrainingEvent
    {
        return TrainingEvent::query()->forCompany($tenantId, $companyEntityId)
            ->where('plan_item_key', $itemKey)->first();
    }
}
