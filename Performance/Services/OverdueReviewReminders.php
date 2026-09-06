<?php

namespace App\Domains\People\Performance\Services;

use App\Domains\People\Performance\Data\OverdueReview;
use App\Domains\People\Performance\Enums\OverdueReviewReason;
use App\Domains\People\Performance\Enums\PerformanceReviewStatus;
use App\Domains\People\Performance\Models\PerformanceReview;
use App\Domains\People\Performance\Models\PerformanceReviewReminder;
use App\Domains\People\Performance\Models\PerformanceReviewResponse;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * A weekly nudge for reviews that have gone quiet.
 *
 * Two silences, two owners. A draft that is a month old is the manager's to
 * finish. A released review the employee has not answered in a fortnight is
 * the manager's to chase. Both are addressed to the reviewer, because the
 * reviewer is the one person the platform knows is accountable for the review.
 *
 * Writing is separate from finding: due() is the rule and answers the question
 * "what is quiet right now", remind() is the record and answers "who has
 * already been told this week". Keeping them apart is what makes --dry-run an
 * honest preview rather than a second implementation of the same rule.
 */
final class OverdueReviewReminders
{
    /** A draft this old is no longer in progress; it has been forgotten. */
    public const STALE_DRAFT_DAYS = 30;

    /** A fortnight is long enough that silence is not simply "not yet". */
    public const UNANSWERED_RESPONSE_DAYS = 14;

    /** @return list<OverdueReview> */
    public function due(int $tenantId, int $companyEntityId, ?DateTimeInterface $asOf = null): array
    {
        $now = $this->moment($asOf);

        $overdue = [];

        $drafts = PerformanceReview::query()->forCompany($tenantId, $companyEntityId)
            ->where('status', PerformanceReviewStatus::Draft)
            ->where('created_at', '<=', $now->subDays(self::STALE_DRAFT_DAYS))
            ->orderBy('id')
            ->get();

        foreach ($drafts as $draft) {
            $overdue[] = new OverdueReview(
                reviewId: (int) $draft->id,
                managerUserId: (int) $draft->reviewer_user_id,
                employeeEntityId: (int) $draft->employee_entity_id,
                reason: OverdueReviewReason::StaleDraft,
                quietSince: CarbonImmutable::instance($draft->created_at),
            );
        }

        $released = PerformanceReview::query()->forCompany($tenantId, $companyEntityId)
            ->where('status', PerformanceReviewStatus::Finalized)
            ->where('finalized_at', '<=', $now->subDays(self::UNANSWERED_RESPONSE_DAYS))
            ->orderBy('id')
            ->get();

        // Superseded versions are excluded by the status filter above: the
        // correction carries the conversation, so chasing the version it
        // replaced would be chasing a document nobody is expected to answer.
        $answered = PerformanceReviewResponse::query()->forCompany($tenantId, $companyEntityId)
            ->whereIn('review_id', $released->modelKeys())
            ->pluck('review_id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();

        foreach ($released as $review) {
            if (in_array((int) $review->id, $answered, true)) {
                continue;
            }

            $overdue[] = new OverdueReview(
                reviewId: (int) $review->id,
                managerUserId: (int) $review->reviewer_user_id,
                employeeEntityId: (int) $review->employee_entity_id,
                reason: OverdueReviewReason::ResponsePending,
                quietSince: CarbonImmutable::instance($review->finalized_at),
            );
        }

        return $overdue;
    }

    /**
     * Write this week's reminders and return only the ones this run created.
     *
     * @return list<PerformanceReviewReminder>
     */
    public function remind(int $tenantId, int $companyEntityId, ?DateTimeInterface $asOf = null): array
    {
        $now = $this->moment($asOf);
        $weekKey = self::weekKey($now);

        $written = [];

        foreach ($this->due($tenantId, $companyEntityId, $now) as $overdue) {
            $reminder = $this->write($tenantId, $companyEntityId, $overdue, $weekKey, $now);

            if ($reminder !== null) {
                $written[] = $reminder;
            }
        }

        return $written;
    }

    /** The ISO year-week a reminder belongs to, e.g. 2026-W37. */
    public static function weekKey(DateTimeInterface $moment): string
    {
        return CarbonImmutable::instance($moment)->format('o-\WW');
    }

    /** Null when this manager was already told about this review this week. */
    private function write(
        int $tenantId,
        int $companyEntityId,
        OverdueReview $overdue,
        string $weekKey,
        CarbonImmutable $now,
    ): ?PerformanceReviewReminder {
        $already = PerformanceReviewReminder::query()->forCompany($tenantId, $companyEntityId)
            ->where('manager_user_id', $overdue->managerUserId)
            ->where('review_id', $overdue->reviewId)
            ->where('week_key', $weekKey)
            ->exists();

        if ($already) {
            return null;
        }

        // The guarantee is the unique key on the table, not the check above:
        // the check only spares the ordinary repeat run a failed insert.

        try {
            return PerformanceReviewReminder::query()->create([
                'tenant_id' => $tenantId,
                'company_entity_id' => $companyEntityId,
                'review_id' => $overdue->reviewId,
                'manager_user_id' => $overdue->managerUserId,
                'reason' => $overdue->reason,
                'week_key' => $weekKey,
                'notified_at' => $now,
            ]);
        } catch (UniqueConstraintViolationException) {
            // Two runs overlapping is the ordinary case this table exists to
            // survive, not an error worth failing the command over.
            return null;
        }
    }

    private function moment(?DateTimeInterface $asOf): CarbonImmutable
    {
        return $asOf === null ? CarbonImmutable::now() : CarbonImmutable::instance($asOf);
    }
}
