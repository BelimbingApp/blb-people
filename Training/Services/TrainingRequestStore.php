<?php

namespace App\Domains\People\Training\Services;

use App\Base\Authz\Contracts\AuthorizationService;
use App\Base\Authz\DTO\Actor;
use App\Base\Tenancy\Contracts\TenantContext;
use App\Core\User\Models\User;
use App\Domains\People\Provider\Contracts\ResolvesWorkforceSubjects;
use App\Domains\People\Provider\Data\ExternalReference;
use App\Domains\People\Provider\Data\WorkforceSubject;
use App\Domains\People\Provider\Enums\WorkforceResourceType;
use App\Domains\People\Skills\Enums\AssessmentStatus;
use App\Domains\People\Skills\Models\SkillAssessment;
use App\Domains\People\Skills\Services\CompanyAttribution;
use App\Domains\People\Training\Data\TrainingRequestDraft;
use App\Domains\People\Training\Enums\TrainingNeedSource;
use App\Domains\People\Training\Enums\TrainingRequestStatus;
use App\Domains\People\Training\Exceptions\InvalidTrainingRequestException;
use App\Domains\People\Training\Models\TrainingRequest;
use App\Domains\People\Training\Models\TrainingRequestDecision;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final readonly class TrainingRequestStore
{
    public const SUBMIT = 'people.training.request.submit';

    public const HOD_RECOMMEND = 'people.training.request.hod-approve';

    public const HR_REVIEW = 'people.training.request.review';

    public const APPROVE = 'people.training.request.approve';

    public function __construct(
        private TenantContext $tenants,
        private AuthorizationService $authorization,
        private CompanyAttribution $companies,
        private ResolvesWorkforceSubjects $subjects,
    ) {}

    public function create(User $actor, int $companyId, TrainingRequestDraft $draft): TrainingRequest
    {
        $tenantId = $this->authorize($actor, $companyId, self::SUBMIT);
        $this->validate($tenantId, $companyId, $draft);

        return DB::transaction(function () use ($actor, $companyId, $draft, $tenantId): TrainingRequest {
            $request = TrainingRequest::query()->forCompany($tenantId, $companyId)->create([
                'tenant_id' => $tenantId, 'company_entity_id' => $companyId,
                'request_key' => (string) Str::uuid(),
                'requestor_provider_id' => $this->provider($draft->requestor),
                'requestor_subject_id' => $draft->requestor->stableId,
                'department_provider_id' => $this->provider($draft->department),
                'department_subject_id' => $draft->department->stableId,
                'need_source' => $draft->needSource, 'need' => trim($draft->need),
                'learning_objective' => trim($draft->learningObjective),
                'expected_result' => trim($draft->expectedResult), 'priority' => $draft->priority,
                'skill_gap_assessment_id' => $draft->skillGapAssessmentId,
                'requirement_version' => $draft->requirementVersion,
                'status' => TrainingRequestStatus::Draft, 'created_by_user_id' => $actor->getKey(),
            ]);
            $this->record($request, 'created', $actor);

            return $request;
        });
    }

    public function submit(User $actor, int $companyId, int $requestId): TrainingRequest
    {
        return $this->move($actor, $companyId, $requestId, TrainingRequestStatus::Draft,
            TrainingRequestStatus::PendingHod, 'submitted', self::SUBMIT);
    }

    public function recommend(User $actor, int $companyId, int $requestId, ?string $notes = null): TrainingRequest
    {
        return $this->move($actor, $companyId, $requestId, TrainingRequestStatus::PendingHod,
            TrainingRequestStatus::PendingHr, 'hod_recommended', self::HOD_RECOMMEND, $notes);
    }

    public function review(User $actor, int $companyId, int $requestId, ?string $notes = null): TrainingRequest
    {
        return $this->move($actor, $companyId, $requestId, TrainingRequestStatus::PendingHr,
            TrainingRequestStatus::PendingApproval, 'hr_reviewed', self::HR_REVIEW, $notes);
    }

    public function approve(User $actor, int $companyId, int $requestId, ?string $notes = null): TrainingRequest
    {
        return $this->move($actor, $companyId, $requestId, TrainingRequestStatus::PendingApproval,
            TrainingRequestStatus::Approved, 'approved', self::APPROVE, $notes);
    }

    public function reject(User $actor, int $companyId, int $requestId, string $notes): TrainingRequest
    {
        $this->required($notes, 'A rejection reason is required.');
        $tenantId = $this->scope($actor, $companyId);

        return DB::transaction(function () use ($actor, $companyId, $requestId, $notes, $tenantId): TrainingRequest {
            $request = $this->find($tenantId, $companyId, $requestId);
            $capability = match ($request->status) {
                TrainingRequestStatus::PendingHod => self::HOD_RECOMMEND,
                TrainingRequestStatus::PendingHr => self::HR_REVIEW,
                TrainingRequestStatus::PendingApproval => self::APPROVE,
                default => throw new InvalidTrainingRequestException('Only a pending training request can be rejected.'),
            };
            $this->authorization->authorize(Actor::forUser($actor), $capability);

            return $this->finish($request, TrainingRequestStatus::Rejected, 'rejected', $actor, $notes);
        });
    }

    public function cancel(User $actor, int $companyId, int $requestId, string $notes): TrainingRequest
    {
        $this->required($notes, 'A cancellation reason is required.');
        $tenantId = $this->authorize($actor, $companyId, self::SUBMIT);

        return DB::transaction(function () use ($actor, $companyId, $requestId, $notes, $tenantId): TrainingRequest {
            $request = $this->find($tenantId, $companyId, $requestId);
            if (! in_array($request->status, [TrainingRequestStatus::Draft, TrainingRequestStatus::PendingHod,
                TrainingRequestStatus::PendingHr, TrainingRequestStatus::PendingApproval], true)) {
                throw new InvalidTrainingRequestException('A terminal training request cannot be cancelled.');
            }

            return $this->finish($request, TrainingRequestStatus::Cancelled, 'cancelled', $actor, $notes);
        });
    }

    private function move(User $actor, int $companyId, int $requestId, TrainingRequestStatus $from,
        TrainingRequestStatus $to, string $decision, string $capability, ?string $notes = null): TrainingRequest
    {
        $tenantId = $this->authorize($actor, $companyId, $capability);

        return DB::transaction(function () use ($actor, $companyId, $requestId, $from, $to, $decision, $notes, $tenantId): TrainingRequest {
            $request = $this->find($tenantId, $companyId, $requestId);
            if ($request->status !== $from) {
                throw new InvalidTrainingRequestException("Request is not awaiting {$from->value}.");
            }

            return $this->finish($request, $to, $decision, $actor, $notes);
        });
    }

    private function finish(TrainingRequest $request, TrainingRequestStatus $status, string $decision,
        User $actor, ?string $notes): TrainingRequest
    {
        $request->update(['status' => $status]);
        $this->record($request, $decision, $actor, $notes);

        return $request->refresh();
    }

    private function authorize(User $actor, int $companyId, string $capability): int
    {
        $tenantId = $this->scope($actor, $companyId);
        $this->authorization->authorize(Actor::forUser($actor), $capability);

        return $tenantId;
    }

    private function scope(User $actor, int $companyId): int
    {
        $tenantId = $this->tenantId();
        if (! $this->companies->mayActFor($actor, $companyId)) {
            throw new InvalidTrainingRequestException('The training request is unavailable in the current company scope.');
        }

        return $tenantId;
    }

    private function validate(int $tenantId, int $companyId, TrainingRequestDraft $draft): void
    {
        foreach ([[$draft->requestor, WorkforceResourceType::Employee],
            [$draft->department, WorkforceResourceType::OrganizationUnit]] as [$subject, $type]) {
            if ($subject->tenantId !== $tenantId || $subject->companyId !== $companyId
                || $subject->type !== $type || $this->subjects->resolve($subject)->record === null) {
                throw new InvalidTrainingRequestException('Requestor and department must be active subjects in this company.');
            }
        }
        foreach ([$draft->need, $draft->learningObjective, $draft->expectedResult] as $text) {
            $this->required($text, 'The need, learning objective, and expected result are required.');
        }
        $hasGap = $draft->skillGapAssessmentId !== null;
        $hasVersion = $draft->requirementVersion !== null;
        if ($hasGap !== $hasVersion || ($draft->needSource === TrainingNeedSource::SkillGap && ! $hasGap)
            || ($hasGap && ($draft->skillGapAssessmentId < 1 || $draft->requirementVersion < 1))) {
            throw new InvalidTrainingRequestException('A skill-gap link requires its pinned requirement version.');
        }
        if ($hasGap && ! SkillAssessment::query()->forCompany($tenantId, $companyId)
            ->whereKey($draft->skillGapAssessmentId)->where('requirement_version', $draft->requirementVersion)
            ->where('status', AssessmentStatus::Finalized)->where('gap', '>', 0)->exists()) {
            throw new InvalidTrainingRequestException('The exact finalized skill gap was not found in this company.');
        }
    }

    private function find(int $tenantId, int $companyId, int $requestId): TrainingRequest
    {
        return TrainingRequest::query()->forCompany($tenantId, $companyId)->whereKey($requestId)->lockForUpdate()->first()
            ?? throw new InvalidTrainingRequestException('Training request was not found in this company.');
    }

    private function record(TrainingRequest $request, string $decision, User $actor, ?string $notes = null): void
    {
        TrainingRequestDecision::query()->forCompany((int) $request->tenant_id, (int) $request->company_entity_id)->create([
            'tenant_id' => $request->tenant_id, 'company_entity_id' => $request->company_entity_id,
            'training_request_id' => $request->id, 'decision' => $decision,
            'actor_user_id' => $actor->getKey(), 'notes' => trim((string) $notes) ?: null, 'occurred_at' => now(),
        ]);
    }

    private function tenantId(): int
    {
        return $this->tenants->currentTenantId()
            ?? throw new InvalidTrainingRequestException('A tenant context is required for training requests.');
    }

    private function provider(WorkforceSubject $subject): string
    {
        return $subject->externalReference?->providerId ?? ExternalReference::PROVIDER_ID;
    }

    private function required(string $value, string $message): void
    {
        if (trim($value) === '') {
            throw new InvalidTrainingRequestException($message);
        }
    }
}
