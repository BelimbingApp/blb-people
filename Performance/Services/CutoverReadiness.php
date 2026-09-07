<?php

namespace App\Domains\People\Performance\Services;

use App\Base\Authz\Contracts\AuthorizationService;
use App\Base\Authz\DTO\Actor;
use App\Core\Employee\Models\Employee;
use App\Core\User\Models\User;
use App\Domains\People\Performance\Data\CutoverCheck;
use App\Domains\People\Performance\Enums\PerformanceReviewStatus;
use App\Domains\People\Performance\Models\PerformanceReview;
use App\Domains\People\Performance\Models\PerformanceReviewEscalation;
use Carbon\CarbonImmutable;
use DateTimeInterface;

/**
 * Whether a company can stop running performance reviews by hand.
 *
 * Four questions, each answered with a count of what is wrong, so the whole
 * report is ready only when every count is zero. The thresholds are borrowed
 * from the lanes that enforce them rather than restated here: a readiness
 * check that disagreed with the reminders about what "overdue" means would be
 * reporting on a different system than the one running.
 */
final class CutoverReadiness
{
    /** An escalation nobody has resolved in this long is still open. */
    private const ESCALATION_GRACE_DAYS = 14;

    private const REVIEW_CAPABILITY = 'people.performance.review.view';

    public function __construct(
        private readonly AuthorizationService $authorization,
    ) {}

    /** @return list<CutoverCheck> */
    public function check(int $tenantId, int $companyEntityId, ?DateTimeInterface $asOf = null): array
    {
        $now = $asOf === null ? CarbonImmutable::now() : CarbonImmutable::instance($asOf);

        return [
            new CutoverCheck(
                'reporting_line',
                'Active employees with no manager',
                $this->employeesWithoutManager($companyEntityId),
            ),
            new CutoverCheck(
                'manager_capability',
                'Managers who cannot read a review',
                $this->managersWithoutCapability($companyEntityId),
            ),
            new CutoverCheck(
                'stale_drafts',
                'Draft reviews past the reminder threshold',
                $this->staleDrafts($tenantId, $companyEntityId, $now),
            ),
            new CutoverCheck(
                'open_escalations',
                'Escalations unanswered beyond the grace period',
                $this->openEscalations($tenantId, $companyEntityId, $now),
            ),
        ];
    }

    /** @param  list<CutoverCheck>  $checks */
    public function ready(array $checks): bool
    {
        foreach ($checks as $check) {
            if (! $check->ready()) {
                return false;
            }
        }

        return true;
    }

    /**
     * Active employees nobody will review: no manager, and not a manager
     * themselves.
     *
     * The top of a reporting tree has no supervisor by construction, and that
     * is not a gap — 0009-d already answers it, routing a manager's own
     * overdue reviews to HR because "no manager above this one does not mean
     * nobody hears about it". A readiness check that called every company's
     * head a blocker could never go green for any real company, and a gate
     * that cannot open is not a gate.
     *
     * What remains a genuine gap is a leaf: somebody with no manager who
     * manages no one, so no reviewer is implied by the tree at all.
     *
     * Only active employees, because cutover is about the people the process
     * will run for.
     */
    private function employeesWithoutManager(int $companyEntityId): int
    {
        $managerIds = $this->activeManagerIds($companyEntityId);

        return Employee::query()
            ->where('company_id', $companyEntityId)
            ->where('status', 'active')
            ->whereNull('supervisor_id')
            ->when($managerIds !== [], static fn ($query) => $query->whereNotIn('id', $managerIds))
            ->count();
    }

    /**
     * Whether the people who will be asked to write reviews can read one.
     * Managing is a fact about the reporting tree, not a role somebody holds.
     *
     * An employee who manages somebody but has no account at all counts too.
     * They cannot do the work either.
     */
    private function managersWithoutCapability(int $companyEntityId): int
    {
        $managerIds = $this->activeManagerIds($companyEntityId);

        if ($managerIds === []) {
            return 0;
        }

        $users = User::query()
            ->where('company_id', $companyEntityId)
            ->whereIn('employee_id', $managerIds)
            ->get()
            ->keyBy(static fn (User $user): int => (int) $user->employee_id);

        $unable = 0;

        foreach ($managerIds as $managerId) {
            $user = $users->get($managerId);

            if ($user === null) {
                $unable++;

                continue;
            }
            if (! $this->authorization->can(Actor::forUser($user), self::REVIEW_CAPABILITY)->allowed) {
                $unable++;
            }
        }

        return $unable;
    }

    /**
     * The employees who supervise at least one active employee.
     *
     * A manager is somebody the process will ask for a review, so the rule
     * turns on live reports: somebody whose only report has left the company
     * manages nobody the cutover cares about, and is a gap themselves rather
     * than the answer to one. Both questions above read the rule from here so
     * the two cannot drift apart.
     *
     * @return list<int>
     */
    private function activeManagerIds(int $companyEntityId): array
    {
        return Employee::query()
            ->where('company_id', $companyEntityId)
            ->where('status', 'active')
            ->whereNotNull('supervisor_id')
            ->distinct()
            ->pluck('supervisor_id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();
    }

    private function staleDrafts(int $tenantId, int $companyEntityId, CarbonImmutable $now): int
    {
        return PerformanceReview::query()->forCompany($tenantId, $companyEntityId)
            ->where('status', PerformanceReviewStatus::Draft)
            ->where('created_at', '<=', $now->subDays(OverdueReviewReminders::STALE_DRAFT_DAYS))
            ->count();
    }

    /**
     * An escalation is open while the review that caused it is still a draft.
     * Finalising the review is the act that answers it, so a finalised one is
     * closed however the escalation row reads.
     */
    private function openEscalations(int $tenantId, int $companyEntityId, CarbonImmutable $now): int
    {
        $draftIds = PerformanceReview::query()->forCompany($tenantId, $companyEntityId)
            ->where('status', PerformanceReviewStatus::Draft)
            ->pluck('id');

        if ($draftIds->isEmpty()) {
            return 0;
        }

        return PerformanceReviewEscalation::query()->forCompany($tenantId, $companyEntityId)
            ->whereIn('review_id', $draftIds)
            ->where('notified_at', '<=', $now->subDays(self::ESCALATION_GRACE_DAYS))
            ->count();
    }
}
