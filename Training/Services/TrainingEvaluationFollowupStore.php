<?php

namespace App\Domains\People\Training\Services;

use App\Base\Authz\Contracts\AuthorizationService;
use App\Base\Authz\DTO\Actor;
use App\Base\Tenancy\Contracts\TenantContext;
use App\Core\User\Models\User;
use App\Domains\People\Skills\Services\CompanyAttribution;
use App\Domains\People\Training\Enums\TrainingEvaluationFollowupKind;
use App\Domains\People\Training\Enums\TrainingEvaluationFollowupStatus;
use App\Domains\People\Training\Exceptions\InvalidTrainingEvaluationException;
use App\Domains\People\Training\Models\TrainingEvaluation;
use App\Domains\People\Training\Models\TrainingEvaluationFollowup;
use App\Domains\People\Training\Models\TrainingEvaluationFollowupAudit;
use Illuminate\Support\Facades\DB;

/**
 * HR follow-up on evaluation support requests and provider concerns.
 *
 * The follow-up is HR's record, not the participant's: every method works
 * only on follow-up rows and their audits, and never writes a column on the
 * evaluation row. One open follow-up per evaluation per kind; closing then
 * reopening the same kind starts a new row so the audit keeps one entry per
 * transition with no rewrites.
 */
final class TrainingEvaluationFollowupStore
{
    public const MANAGE = 'people.training.evaluation.followup.manage';

    public function __construct(
        private readonly TenantContext $tenancy,
        private readonly CompanyAttribution $companies,
        private readonly AuthorizationService $authorization,
    ) {}

    public function open(User $actor, int $companyId, int $evaluationId, string $kind): TrainingEvaluationFollowup
    {
        $tenant = $this->scope($actor, $companyId);
        $kind = TrainingEvaluationFollowupKind::tryFrom($kind)
            ?? $this->deny('Unknown follow-up kind.');
        $evaluation = TrainingEvaluation::query()->forCompany($tenant, $companyId)
            ->whereKey($evaluationId)->first() ?? $this->deny();

        $alreadyOpen = TrainingEvaluationFollowup::query()->forCompany($tenant, $companyId)
            ->where('evaluation_id', $evaluation->id)
            ->where('kind', $kind)
            ->where('status', TrainingEvaluationFollowupStatus::Open)
            ->exists();
        if ($alreadyOpen) {
            throw new InvalidTrainingEvaluationException('There is already an open follow-up of this kind for this evaluation. Progress or close it first.');
        }

        return DB::transaction(function () use ($tenant, $companyId, $evaluation, $kind, $actor): TrainingEvaluationFollowup {
            $followup = TrainingEvaluationFollowup::query()->create([
                'tenant_id' => $tenant,
                'company_entity_id' => $companyId,
                'evaluation_id' => (int) $evaluation->id,
                'kind' => $kind,
                'status' => TrainingEvaluationFollowupStatus::Open,
                'action_taken' => null,
                'closed_at' => null,
                'created_by_user_id' => (int) $actor->getKey(),
                'updated_by_user_id' => (int) $actor->getKey(),
            ]);
            $this->audit($followup, $actor, null);

            return $followup->refresh();
        });
    }

    public function progress(User $actor, int $companyId, int $followupId, ?string $actionTaken): TrainingEvaluationFollowup
    {
        $tenant = $this->scope($actor, $companyId);
        $followup = $this->followup($tenant, $companyId, $followupId);
        if ($followup->status === TrainingEvaluationFollowupStatus::Closed) {
            throw new InvalidTrainingEvaluationException('This follow-up is already closed. Open a new one to follow up again.');
        }
        $actionTaken = $this->actionTaken($actionTaken);

        return DB::transaction(function () use ($followup, $actor, $actionTaken): TrainingEvaluationFollowup {
            $followup->update([
                'status' => TrainingEvaluationFollowupStatus::InProgress,
                'action_taken' => $actionTaken,
                'updated_by_user_id' => (int) $actor->getKey(),
            ]);
            $this->audit($followup->refresh(), $actor, $actionTaken);

            return $followup->refresh();
        });
    }

    public function close(User $actor, int $companyId, int $followupId, ?string $actionTaken): TrainingEvaluationFollowup
    {
        $tenant = $this->scope($actor, $companyId);
        $followup = $this->followup($tenant, $companyId, $followupId);
        if ($followup->status === TrainingEvaluationFollowupStatus::Closed) {
            throw new InvalidTrainingEvaluationException('This follow-up is already closed.');
        }
        $actionTaken = $this->actionTaken($actionTaken);
        if ($actionTaken === null) {
            throw new InvalidTrainingEvaluationException('Closing a follow-up needs the action taken written down first.');
        }

        return DB::transaction(function () use ($followup, $actor, $actionTaken): TrainingEvaluationFollowup {
            $followup->update([
                'status' => TrainingEvaluationFollowupStatus::Closed,
                'action_taken' => $actionTaken,
                'closed_at' => now(),
                'updated_by_user_id' => (int) $actor->getKey(),
            ]);
            $this->audit($followup->refresh(), $actor, $actionTaken);

            return $followup->refresh();
        });
    }

    private function followup(int $tenant, int $companyId, int $followupId): TrainingEvaluationFollowup
    {
        return TrainingEvaluationFollowup::query()->forCompany($tenant, $companyId)
            ->whereKey($followupId)->first() ?? $this->deny();
    }

    private function audit(TrainingEvaluationFollowup $followup, User $actor, ?string $actionTaken): void
    {
        TrainingEvaluationFollowupAudit::query()->create([
            'tenant_id' => $followup->tenant_id,
            'company_entity_id' => $followup->company_entity_id,
            'training_evaluation_followup_id' => (int) $followup->id,
            'kind' => $followup->kind->value,
            'status' => $followup->status->value,
            'action_taken' => $actionTaken,
            'actor_user_id' => (int) $actor->getKey(),
            'occurred_at' => now(),
        ]);
    }

    private function actionTaken(?string $actionTaken): ?string
    {
        $actionTaken = trim((string) $actionTaken);
        if (mb_strlen($actionTaken) > 2000) {
            throw new InvalidTrainingEvaluationException('Keep the action taken to 2,000 characters or fewer.');
        }

        return $actionTaken === '' ? null : $actionTaken;
    }

    private function scope(User $actor, int $companyId): int
    {
        $tenant = $this->tenancy->currentTenantId();
        $currentActor = $actor->exists ? User::query()->find($actor->getKey()) : null;
        if ($tenant === null || $currentActor === null || $currentActor->getCompanyId() !== $actor->getCompanyId()
            || (int) $currentActor->tenant_id !== $tenant || ! $this->companies->mayActFor($actor, $companyId)) {
            $this->deny();
        }
        $this->authorization->authorize(Actor::forUser($actor), self::MANAGE);

        return $tenant;
    }

    private function deny(string $message = 'The training evaluation follow-up is unavailable in the current scope.'): never
    {
        throw new InvalidTrainingEvaluationException($message);
    }
}
