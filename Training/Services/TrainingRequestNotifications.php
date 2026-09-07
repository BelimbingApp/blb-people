<?php

namespace App\Domains\People\Training\Services;

use App\Core\User\Models\User;
use App\Domains\People\Provider\Data\ExternalReference;
use App\Domains\People\Provider\Enums\WorkforceResourceType;
use App\Domains\People\Settings\Models\PeopleNotificationDeliveryLog;
use App\Domains\People\Skills\Services\DepartmentHeads;
use App\Domains\People\Skills\Services\WorkforceSubjects;
use App\Domains\People\Training\Models\TrainingRequest;
use App\Domains\People\Training\Models\TrainingRequestDecision;
use App\Domains\People\Training\Notifications\TrainingRequestTransitionNotification;

/**
 * Tell the next person in a training request's workflow that it moved (0010-g).
 *
 * Who is told follows the decision just recorded: a submission goes to the
 * requestor's head of department, a recommendation to HR reviewers, a review
 * to approvers, and every terminal decision to the requestor and to the HOD
 * who recommended it. People are resolved through the workforce seam and the
 * authz grants, never from request input; anyone the seam cannot resolve is
 * skipped and the transition still stands. The actor is never their own
 * recipient: a requestor cancelling their request does not need telling.
 *
 * One PeopleNotificationDeliveryLog row per recipient and decision row is
 * written before notify(), keyed by metadata.decision_id, so a transition
 * replayed for the same decision row cannot notify anybody twice.
 */
final class TrainingRequestNotifications
{
    public const CHANNEL = 'database';

    public const SUBJECT_PREFIX = 'people.training.request.';

    public function __construct(
        private readonly TrainingCapabilityHolders $holders,
        private readonly DepartmentHeads $heads,
        private readonly WorkforceSubjects $subjects,
    ) {}

    /**
     * @return list<PeopleNotificationDeliveryLog> the rows this call wrote
     */
    public function notify(TrainingRequest $request, TrainingRequestDecision $decision, User $actor): array
    {
        $companyId = (int) $request->company_entity_id;
        $written = [];

        foreach ($this->recipients($request, $decision, $actor) as $recipient) {
            if ($this->alreadyLogged($request, $decision, $recipient)) {
                continue;
            }

            $log = PeopleNotificationDeliveryLog::query()->create([
                'company_id' => $companyId,
                'notifiable_type' => TrainingRequest::class,
                'notifiable_id' => $request->getKey(),
                'channel' => self::CHANNEL,
                'recipient' => (string) $recipient->getKey(),
                'subject' => self::SUBJECT_PREFIX.$decision->decision,
                'status' => 'sent',
                'sent_at' => now(),
                'metadata' => [
                    'decision_id' => (int) $decision->getKey(),
                    'decision' => (string) $decision->decision,
                    'training_request_id' => (int) $request->getKey(),
                    'recipient_user_id' => (int) $recipient->getKey(),
                    'actor_user_id' => (int) $actor->getKey(),
                ],
            ]);

            $recipient->notify(new TrainingRequestTransitionNotification(
                trainingRequestId: (int) $request->getKey(),
                companyEntityId: $companyId,
                decisionId: (int) $decision->getKey(),
                decision: (string) $decision->decision,
                actorName: (string) $actor->name,
                url: route('people.training.requests.index'),
            ));

            $written[] = $log;
        }

        return $written;
    }

    /**
     * Who a decision is addressed to, each once, never the actor.
     *
     * @return list<User>
     */
    private function recipients(TrainingRequest $request, TrainingRequestDecision $decision, User $actor): array
    {
        $companyId = (int) $request->company_entity_id;

        $users = match ((string) $decision->decision) {
            'submitted' => $this->users([$this->headUserOf($request)]),
            'hod_recommended' => $this->holders->users($companyId, TrainingRequestStore::HR_REVIEW),
            'hr_reviewed' => $this->holders->users($companyId, TrainingRequestStore::APPROVE),
            'approved', 'rejected', 'cancelled' => $this->users([
                $this->requestorUserOf($request),
                $this->recommendingHodOf($request),
            ]),
            default => [],
        };

        $unique = [];
        foreach ($users as $user) {
            if ((int) $user->getKey() === (int) $actor->getKey()) {
                continue;
            }
            $unique[(int) $user->getKey()] = $user;
        }

        return array_values($unique);
    }

    /** The user account heading the requestor's department, when the seam can name one. */
    private function headUserOf(TrainingRequest $request): ?int
    {
        if ((string) $request->requestor_provider_id !== ExternalReference::PROVIDER_ID) {
            return null;
        }

        return $this->heads->headUserOf((int) $request->company_entity_id, (int) $request->requestor_subject_id);
    }

    /** The requestor's platform user through the workforce seam, when they still resolve. */
    private function requestorUserOf(TrainingRequest $request): ?int
    {
        if ((string) $request->requestor_provider_id !== ExternalReference::PROVIDER_ID) {
            return null;
        }

        $employee = $this->subjects->resolve(
            (int) $request->tenant_id,
            (int) $request->company_entity_id,
            WorkforceResourceType::Employee,
            (int) $request->requestor_subject_id,
        );
        $userId = $employee?->userReference?->externalId;

        return $userId === null ? null : (int) $userId;
    }

    /** The actor of this request's `hod_recommended` decision row, if the HOD ever recommended it. */
    private function recommendingHodOf(TrainingRequest $request): ?int
    {
        $userId = TrainingRequestDecision::query()
            ->forCompany((int) $request->tenant_id, (int) $request->company_entity_id)
            ->where('training_request_id', $request->getKey())
            ->where('decision', 'hod_recommended')
            ->orderByDesc('id')
            ->value('actor_user_id');

        return $userId === null ? null : (int) $userId;
    }

    /**
     * @param  list<int|null>  $userIds
     * @return list<User>
     */
    private function users(array $userIds): array
    {
        $ids = array_values(array_unique(array_filter($userIds, static fn (?int $id): bool => $id !== null)));

        if ($ids === []) {
            return [];
        }

        return User::query()->whereIn('id', $ids)->orderBy('id')->get()->all();
    }

    private function alreadyLogged(TrainingRequest $request, TrainingRequestDecision $decision, User $recipient): bool
    {
        return PeopleNotificationDeliveryLog::query()
            ->where('company_id', (int) $request->company_entity_id)
            ->where('notifiable_type', TrainingRequest::class)
            ->where('notifiable_id', $request->getKey())
            ->where('recipient', (string) $recipient->getKey())
            ->where('metadata->decision_id', (int) $decision->getKey())
            ->exists();
    }
}
