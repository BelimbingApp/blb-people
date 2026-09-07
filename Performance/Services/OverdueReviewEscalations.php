<?php

namespace App\Domains\People\Performance\Services;

use App\Core\Employee\Models\Employee;
use App\Core\User\Models\User;
use App\Domains\People\Performance\Data\EscalatedReview;
use App\Domains\People\Performance\Enums\EscalationAudience;
use App\Domains\People\Performance\Models\PerformanceReviewEscalation;
use App\Domains\People\Performance\Models\PerformanceReviewReminder;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * A review still ignored after two weekly reminders stops being the manager's
 * problem alone.
 *
 * The trigger is read from the reminder rows rather than recomputed: 0009-c
 * already writes one row per manager, review and ISO week, so "two consecutive
 * weeks" is a fact the table holds. That is what the week key was for.
 *
 * Escalating is deliberately slower than reminding. A fortnight of silence is
 * a judgement about the manager, and one that arrives after a single missed
 * week would be wrong often enough to be ignored.
 */
final class OverdueReviewEscalations
{
    /** @return list<EscalatedReview> */
    public function due(int $tenantId, int $companyEntityId, ?DateTimeInterface $asOf = null): array
    {
        $now = $this->moment($asOf);
        $thisWeek = OverdueReviewReminders::weekKey($now);
        $lastWeek = OverdueReviewReminders::weekKey($now->subWeek());

        $reminders = PerformanceReviewReminder::query()->forCompany($tenantId, $companyEntityId)
            ->whereIn('week_key', [$thisWeek, $lastWeek])
            ->orderBy('id')
            ->get();

        $escalations = [];

        foreach ($reminders->groupBy(
            static fn (PerformanceReviewReminder $reminder): string => $reminder->review_id.':'.$reminder->manager_user_id,
        ) as $pair) {
            $weeks = $pair->pluck('week_key')->unique();

            // Both weeks, not merely two rows: two reminders inside one week
            // cannot happen, but reading it this way says what is meant.
            if (! $weeks->contains($thisWeek) || ! $weeks->contains($lastWeek)) {
                continue;
            }

            $managerUserId = (int) $pair->first()->manager_user_id;
            $target = $this->managersManager($companyEntityId, $managerUserId);

            $escalations[] = new EscalatedReview(
                reviewId: (int) $pair->first()->review_id,
                managerUserId: $managerUserId,
                escalatedToUserId: $target,
                // No manager above this one does not mean nobody hears about
                // it. HR is the reader of last resort, never silence.
                audience: $target === null ? EscalationAudience::Hr : EscalationAudience::Manager,
            );
        }

        return $escalations;
    }

    /**
     * Write this fortnight's escalations and return only the ones created.
     *
     * @return list<PerformanceReviewEscalation>
     */
    public function escalate(int $tenantId, int $companyEntityId, ?DateTimeInterface $asOf = null): array
    {
        $now = $this->moment($asOf);
        $fortnightKey = self::fortnightKey($now);

        $written = [];

        foreach ($this->due($tenantId, $companyEntityId, $now) as $escalated) {
            $row = $this->write($tenantId, $companyEntityId, $escalated, $fortnightKey, $now);

            if ($row !== null) {
                $written[] = $row;
            }
        }

        return $written;
    }

    /**
     * The fortnight a row belongs to, e.g. 2026-F18.
     *
     * Pairs of ISO weeks, so the key changes at the same boundary the weekly
     * reminder does and a fortnight can never straddle two years.
     */
    public static function fortnightKey(DateTimeInterface $moment): string
    {
        $moment = CarbonImmutable::instance($moment);

        return sprintf('%s-F%02d', $moment->format('o'), intdiv((int) $moment->format('W'), 2));
    }

    /** The user the manager reports to, or null when the line ends here. */
    private function managersManager(int $companyEntityId, int $managerUserId): ?int
    {
        $manager = User::query()->whereKey($managerUserId)->first();

        if ($manager?->employee_id === null) {
            return null;
        }

        $supervisorId = Employee::query()
            ->where('company_id', $companyEntityId)
            ->whereKey($manager->employee_id)
            ->value('supervisor_id');

        if ($supervisorId === null) {
            return null;
        }

        // The supervisor is an employee; escalating needs the account that
        // reads it, and an employee without one is the same as no manager.
        $userId = User::query()
            ->where('company_id', $companyEntityId)
            ->where('employee_id', $supervisorId)
            ->value('id');

        return $userId === null ? null : (int) $userId;
    }

    /** Null when this review was already escalated this fortnight. */
    private function write(
        int $tenantId,
        int $companyEntityId,
        EscalatedReview $escalated,
        string $fortnightKey,
        CarbonImmutable $now,
    ): ?PerformanceReviewEscalation {
        $already = PerformanceReviewEscalation::query()->forCompany($tenantId, $companyEntityId)
            ->where('review_id', $escalated->reviewId)
            ->where('fortnight_key', $fortnightKey)
            ->exists();

        if ($already) {
            return null;
        }

        try {
            return PerformanceReviewEscalation::query()->create([
                'tenant_id' => $tenantId,
                'company_entity_id' => $companyEntityId,
                'review_id' => $escalated->reviewId,
                'manager_user_id' => $escalated->managerUserId,
                'escalated_to_user_id' => $escalated->escalatedToUserId,
                'audience' => $escalated->audience,
                'fortnight_key' => $fortnightKey,
                'notified_at' => $now,
            ]);
        } catch (UniqueConstraintViolationException) {
            // Two runs overlapping is the case the unique key exists for.
            return null;
        }
    }

    private function moment(?DateTimeInterface $asOf): CarbonImmutable
    {
        return $asOf === null ? CarbonImmutable::now() : CarbonImmutable::instance($asOf);
    }
}
