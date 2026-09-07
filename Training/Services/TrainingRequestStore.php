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
use App\Domains\People\Skills\Services\WorkforceSubjects;
use App\Domains\People\Training\Data\TrainingRequestDraft;
use App\Domains\People\Training\Data\TrainingRequestSubjectsDraft;
use App\Domains\People\Training\Enums\TrainingEventStatus;
use App\Domains\People\Training\Enums\TrainingNeedSource;
use App\Domains\People\Training\Enums\TrainingRequestStatus;
use App\Domains\People\Training\Exceptions\InvalidTrainingRequestException;
use App\Domains\People\Training\Models\TrainingEvent;
use App\Domains\People\Training\Models\TrainingRequest;
use App\Domains\People\Training\Models\TrainingRequestDecision;
use App\Domains\People\Training\Models\TrainingRequestSubject;
use Illuminate\Database\Eloquent\Builder;
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
        private TrainingBudgetStore $budgets,
    ) {}

    /**
     * @param  TrainingRequestSubjectsDraft|null  $subjects  who the request is
     *                                                       for; null means the requestor themselves, which is what a request has
     *                                                       always meant here. An explicitly empty list is refused rather than
     *                                                       silently treated the same way.
     */
    public function create(User $actor, int $companyId, TrainingRequestDraft $draft, ?TrainingRequestSubjectsDraft $subjects = null): TrainingRequest
    {
        $tenantId = $this->authorize($actor, $companyId, self::SUBMIT);
        $this->validate($tenantId, $companyId, $draft);
        $resolved = $this->resolveSubjects($actor, $tenantId, $companyId, $draft,
            $subjects ?? TrainingRequestSubjectsDraft::forSubjects([$draft->requestor]));

        return DB::transaction(function () use ($actor, $companyId, $draft, $tenantId, $resolved): TrainingRequest {
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
                'estimated_cost' => $draft->estimatedCost,
                'skill_gap_assessment_id' => $draft->skillGapAssessmentId,
                'requirement_version' => $draft->requirementVersion,
                'status' => TrainingRequestStatus::Draft, 'created_by_user_id' => $actor->getKey(),
            ]);
            foreach ($resolved as $subject) {
                TrainingRequestSubject::query()->forCompany($tenantId, $companyId)->create([
                    'tenant_id' => $tenantId, 'company_entity_id' => $companyId,
                    'training_request_id' => $request->id,
                    'provider_id' => $subject['provider_id'],
                    'employee_subject_id' => $subject['employee_subject_id'],
                    'workforce_observed_at' => $subject['workforce_observed_at'],
                    'source' => $subject['source'],
                    'cohort_reference' => $subject['cohort_reference'],
                ]);
            }
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

    /**
     * Approve a reviewed request, subject to the department's training budget.
     *
     * The budget is only worth having if approving is where it bites. A page
     * that reports an overspend afterwards reports a decision nobody was
     * stopped from making, so the refusal lives here rather than in a report.
     *
     * $budgetOverrideReason is the stated case for spending past the
     * allocation. It is worth nothing without people.training.budget.unlock,
     * and the capability is worth nothing without it.
     */
    public function approve(
        User $actor,
        int $companyId,
        int $requestId,
        ?string $notes = null,
        ?string $budgetOverrideReason = null,
    ): TrainingRequest {
        $tenantId = $this->authorize($actor, $companyId, self::APPROVE);
        $this->assertWithinBudget($tenantId, $companyId, $requestId, $actor, $budgetOverrideReason);

        return $this->move($actor, $companyId, $requestId, TrainingRequestStatus::PendingApproval,
            TrainingRequestStatus::Approved, 'approved', self::APPROVE, $notes);
    }

    /**
     * Link an approved request to the scheduled event that satisfies it
     * (0010-d). The event must be in this company and still ahead (scheduled
     * or in progress); the link is a fact on the request plus one decision
     * row, so the trail keeps every link and unlink ever made.
     */
    public function linkEvent(User $actor, int $companyId, int $requestId, int $eventId): TrainingRequest
    {
        $tenantId = $this->authorize($actor, $companyId, self::HR_REVIEW);

        return DB::transaction(function () use ($actor, $companyId, $requestId, $eventId, $tenantId): TrainingRequest {
            $request = $this->find($tenantId, $companyId, $requestId);
            if ($request->status !== TrainingRequestStatus::Approved) {
                throw new InvalidTrainingRequestException('Only an approved training request can be linked to an event.');
            }
            $event = TrainingEvent::query()->forCompany($tenantId, $companyId)->whereKey($eventId)->first()
                ?? throw new InvalidTrainingRequestException('Training event was not found in this company.');
            if (! in_array($event->status, [TrainingEventStatus::Scheduled, TrainingEventStatus::InProgress], true)) {
                throw new InvalidTrainingRequestException('Only a scheduled or in-progress training event can satisfy a request.');
            }

            $request->update(['training_event_id' => $event->id, 'linked_by_user_id' => $actor->getKey(), 'linked_at' => now()]);
            $this->record($request, 'linked', $actor, "Linked to event {$event->id}: {$event->course_title_snapshot}");

            return $request->refresh();
        });
    }

    public function unlinkEvent(User $actor, int $companyId, int $requestId, ?string $notes = null): TrainingRequest
    {
        $tenantId = $this->authorize($actor, $companyId, self::HR_REVIEW);

        return DB::transaction(function () use ($actor, $companyId, $requestId, $notes, $tenantId): TrainingRequest {
            $request = $this->find($tenantId, $companyId, $requestId);
            if ($request->training_event_id === null) {
                throw new InvalidTrainingRequestException('The training request is not linked to an event.');
            }
            $eventId = (int) $request->training_event_id;
            $request->update(['training_event_id' => null, 'linked_by_user_id' => null, 'linked_at' => null]);
            $this->record($request, 'unlinked', $actor, trim("Unlinked from event {$eventId}. ".(string) $notes));

            return $request->refresh();
        });
    }

    /** Approved requests no scheduled event satisfies yet: the HR queue's "approved, not linked" section. */
    public function approvedUnlinkedQuery(int $tenantId, int $companyId): Builder
    {
        return TrainingRequest::query()->forCompany($tenantId, $companyId)
            ->where('status', TrainingRequestStatus::Approved->value)
            ->whereNull('training_event_id')
            ->orderBy('id');
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

    /**
     * Refuse an approval that would take the department past its allocation,
     * unless it is deliberately and accountably overridden.
     *
     * Three cases pass straight through, and each is a different kind of
     * "there is nothing to compare": a request nobody has priced, a department
     * with no allocation, and a cost that still fits.
     */
    private function assertWithinBudget(
        int $tenantId,
        int $companyId,
        int $requestId,
        User $actor,
        ?string $overrideReason,
    ): void {
        $request = $this->find($tenantId, $companyId, $requestId);
        $cost = $request->estimated_cost;

        if ($cost === null) {
            return;
        }

        $remaining = $this->budgets->remainingFor(
            $tenantId, $companyId, (int) $request->department_subject_id, (int) now()->year,
        );

        // No allocation is not an allocation of nothing, here as on the page.
        if ($remaining === null) {
            return;
        }

        // Exactly to the limit is inside it: refusing at equality would make a
        // budget of 1,000 mean 999.9999.
        if (bccomp((string) $cost, $remaining, 4) <= 0) {
            return;
        }

        $overage = bcsub((string) $cost, $remaining, 4);

        if ($overrideReason === null || trim($overrideReason) === '') {
            throw new InvalidTrainingRequestException(
                "This approval would exceed the department training budget: remaining {$remaining}, requested {$cost}."
                .' State a reason and hold '.TrainingBudgetStore::UNLOCK.' to approve it anyway.',
            );
        }

        $this->authorization->authorize(Actor::forUser($actor), TrainingBudgetStore::UNLOCK);

        $this->budgets->recordOverride(
            $tenantId, $companyId, (int) $request->department_subject_id, (int) now()->year,
            (string) $cost, $overage, $overrideReason, (int) $actor->getKey(),
        );
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

    /**
     * The people a request is for, as rows ready to store.
     *
     * Resolved now and frozen: an approval months later enrols the roster the
     * approver saw. A request that resolves to nobody is refused rather than
     * stored empty, because an empty request is one nobody can act on and
     * everybody has to read twice.
     *
     * @return list<array{provider_id: string, employee_subject_id: string, workforce_observed_at: string, source: string, cohort_reference: string|null}>
     */
    private function resolveSubjects(User $actor, int $tenantId, int $companyId, TrainingRequestDraft $draft, TrainingRequestSubjectsDraft $subjects): array
    {
        $rows = $subjects->cohort
            ? $this->cohortRows($companyId, $draft)
            : $this->namedRows($tenantId, $companyId, $subjects->subjects);

        $this->authorizeSubjects($actor, $companyId, $draft, $rows, $subjects->cohort);

        if ($rows === []) {
            throw new InvalidTrainingRequestException('A training request names at least one person who will attend.');
        }

        return $rows;
    }

    /**
     * Who this actor may put on a request.
     *
     * A head of department speaks for the department: they may name its
     * members, or take the whole cohort. Anybody else speaks only for
     * themselves, which is what the submit capability means — it is a request
     * for training, not an instruction that somebody else attend.
     *
     * @param  list<array{employee_subject_id: string, ...}>  $rows
     */
    private function authorizeSubjects(User $actor, int $companyId, TrainingRequestDraft $draft, array $rows, bool $cohort): void
    {
        if ($this->authorization->can(Actor::forUser($actor), self::HOD_RECOMMEND)->allowed) {
            $this->authorizeDepartmentSubjects($companyId, $draft, $rows);

            return;
        }

        if ($cohort) {
            throw new InvalidTrainingRequestException('Only a head of department may request training for a whole department.');
        }

        $self = (string) ($draft->requestor->stableId);
        foreach ($rows as $row) {
            if ($row['employee_subject_id'] !== $self) {
                throw new InvalidTrainingRequestException('Request training for yourself, or ask the head of department to request it for somebody else.');
            }
        }
    }

    /**
     * A head of department names their own department's people, not a peer's.
     *
     * @param  list<array{employee_subject_id: string, ...}>  $rows
     */
    private function authorizeDepartmentSubjects(int $companyId, TrainingRequestDraft $draft, array $rows): void
    {
        $department = $draft->department->stableId;
        $members = [];
        foreach (app(WorkforceSubjects::class)->employees($companyId) as $employee) {
            if ($employee->organizationReference?->externalId === $department) {
                $members[(string) $employee->reference->externalId] = true;
            }
        }

        foreach ($rows as $row) {
            if (! isset($members[$row['employee_subject_id']])) {
                throw new InvalidTrainingRequestException('A subject must belong to the department the request names.');
            }
        }
    }

    /**
     * @param  list<WorkforceSubject>  $subjects
     * @return list<array{provider_id: string, employee_subject_id: string, workforce_observed_at: string, source: string, cohort_reference: string|null}>
     */
    private function namedRows(int $tenantId, int $companyId, array $subjects): array
    {
        $rows = [];

        foreach ($subjects as $subject) {
            if ($subject->tenantId !== $tenantId || $subject->companyId !== $companyId
                || $subject->type !== WorkforceResourceType::Employee
                || $this->subjects->resolve($subject)->record === null) {
                throw new InvalidTrainingRequestException('Every named subject must be an active employee of this company.');
            }

            $rows[] = [
                'provider_id' => $this->provider($subject),
                'employee_subject_id' => $subject->stableId,
                'workforce_observed_at' => now()->toDateTimeString(),
                'source' => TrainingRequestSubject::SOURCE_INDIVIDUAL,
                'cohort_reference' => null,
            ];
        }

        return $rows;
    }

    /**
     * The request's own department, as it stands right now.
     *
     * Inactive employees are left out: a cohort is who would attend, and
     * somebody who has left the company would not.
     *
     * @return list<array{provider_id: string, employee_subject_id: string, workforce_observed_at: string, source: string, cohort_reference: string|null}>
     */
    private function cohortRows(int $companyId, TrainingRequestDraft $draft): array
    {
        $department = $draft->department->stableId;
        $rows = [];

        foreach (app(WorkforceSubjects::class)->employees($companyId) as $employee) {
            if (! $employee->active || $employee->organizationReference?->externalId !== $department) {
                continue;
            }

            $rows[] = [
                'provider_id' => $employee->reference->providerId,
                'employee_subject_id' => (string) $employee->reference->externalId,
                'workforce_observed_at' => now()->toDateTimeString(),
                'source' => TrainingRequestSubject::SOURCE_COHORT,
                'cohort_reference' => $department,
            ];
        }

        return $rows;
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
