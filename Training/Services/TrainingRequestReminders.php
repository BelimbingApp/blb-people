<?php

namespace App\Domains\People\Training\Services;

use App\Base\Authz\Enums\PrincipalType;
use App\Base\Authz\Models\PrincipalRole;
use App\Core\User\Models\User;
use App\Domains\People\Training\Data\DueTrainingRequest;
use App\Domains\People\Training\Livewire\Requests\Register;
use App\Domains\People\Training\Models\TrainingRequestDecision;
use App\Domains\People\Training\Models\TrainingRequestReminder;
use App\Domains\People\Training\Notifications\TrainingRequestReminderNotification;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Remind HR of approved training requests that still have no event, once per
 * request, recipient and ISO week, after a configured age.
 *
 * The unit is the approved request. Its age is the day the `approved`
 * decision row was recorded, not the request's created_at: a request that
 * waited a month for approval and was approved yesterday has not been
 * neglected yet. Dates are compared in PHP after loading, as in
 * TrainingEvaluationReminders: occurred_at is a timestamp and the boundary
 * is a calendar day, so a string predicate would silently miss the last day.
 *
 * Writing is separate from finding: due() is the rule, remind() is the
 * record, and --dry-run reads the former. The reminder row is inserted before
 * the notification is sent so an overlapping run loses the insert rather than
 * sending a second message; the unique key, not the exists() check, is the
 * guarantee.
 */
final class TrainingRequestReminders
{
    public const AGE_CONFIG = 'people-training.requests.unlinked_reminder_days';

    public const DEFAULT_AGE_DAYS = 14;

    /** The HR grant a recipient must hold in the company. */
    public const RECIPIENT_CAPABILITY = TrainingRequestStore::HR_REVIEW;

    public function __construct(private readonly TrainingRequestStore $requests) {}

    /**
     * Approved requests in this company with no event whose approval is at
     * least the configured number of days old, oldest approval first.
     *
     * @return list<DueTrainingRequest>
     */
    public function due(int $tenantId, int $companyEntityId, ?DateTimeInterface $asOf = null): array
    {
        $today = $this->moment($asOf)->startOfDay();
        $age = self::ageDays();

        $requests = $this->requests->approvedUnlinkedQuery($tenantId, $companyEntityId)->get()->keyBy('id');

        if ($requests->isEmpty()) {
            return [];
        }

        // The latest `approved` row per request: a request re-approved after
        // an unlink starts its clock again from the newer decision.
        $approvals = TrainingRequestDecision::query()->forCompany($tenantId, $companyEntityId)
            ->whereIn('training_request_id', $requests->keys()->all())
            ->where('decision', 'approved')
            ->orderBy('id')
            ->get()
            ->keyBy('training_request_id');

        $rows = [];

        foreach ($approvals as $requestId => $approval) {
            $request = $requests->get($requestId);

            if ($request === null) {
                continue;
            }

            $approvedOn = CarbonImmutable::instance($approval->occurred_at)->startOfDay();
            $days = self::daysBetween($approvedOn, $today);

            if ($days < $age) {
                continue;
            }

            $rows[] = new DueTrainingRequest(
                requestId: (int) $request->id,
                need: (string) $request->need,
                approvedOn: $approvedOn,
                daysUnlinked: $days,
            );
        }

        usort($rows, static fn (DueTrainingRequest $a, DueTrainingRequest $b): int => [$a->approvedOn, $a->requestId] <=> [$b->approvedOn, $b->requestId]);

        return $rows;
    }

    /**
     * Write this week's reminders, notify each new recipient, and return only
     * the rows this run created.
     *
     * @return list<TrainingRequestReminder>
     */
    public function remind(int $tenantId, int $companyEntityId, ?DateTimeInterface $asOf = null): array
    {
        $now = $this->moment($asOf);
        $weekKey = self::weekKey($now);
        $due = $this->due($tenantId, $companyEntityId, $now);

        if ($due === []) {
            return [];
        }

        $recipients = $this->recipients($companyEntityId);
        $url = route('people.training.requests.register', ['status' => Register::FILTER_APPROVED_UNLINKED]);
        $written = [];

        foreach ($due as $row) {
            foreach ($recipients as $recipient) {
                $already = TrainingRequestReminder::query()->forCompany($tenantId, $companyEntityId)
                    ->where('training_request_id', $row->requestId)
                    ->where('week_key', $weekKey)
                    ->where('recipient_user_id', $recipient->id)
                    ->exists();

                if ($already) {
                    continue;
                }

                // The insert gets its own transaction (a savepoint under an
                // outer one) so a unique violation is contained: PostgreSQL
                // aborts the whole enclosing transaction otherwise.
                try {
                    $reminder = DB::transaction(static fn (): TrainingRequestReminder => TrainingRequestReminder::query()->create([
                        'tenant_id' => $tenantId,
                        'company_entity_id' => $companyEntityId,
                        'training_request_id' => $row->requestId,
                        'week_key' => $weekKey,
                        'recipient_user_id' => $recipient->id,
                        'sent_at' => $now,
                    ]));
                } catch (UniqueConstraintViolationException) {
                    // Two runs overlapping is what the unique key is for.
                    continue;
                }

                $recipient->notify(new TrainingRequestReminderNotification($row, $companyEntityId, $url, $weekKey));
                $written[] = $reminder;
            }
        }

        return $written;
    }

    /**
     * HR users granted the review capability in this company. Explicit role
     * holders only: a grant-all policy is not an inbox.
     *
     * @return list<User>
     */
    public function recipients(int $companyEntityId): array
    {
        $userIds = PrincipalRole::query()
            ->join('base_authz_roles', 'base_authz_roles.id', '=', 'base_authz_principal_roles.role_id')
            ->join('base_authz_role_capabilities', 'base_authz_role_capabilities.role_id', '=', 'base_authz_roles.id')
            ->where('base_authz_principal_roles.principal_type', PrincipalType::USER->value)
            ->where('base_authz_principal_roles.company_id', $companyEntityId)
            ->where('base_authz_role_capabilities.capability_key', self::RECIPIENT_CAPABILITY)
            ->distinct()
            ->pluck('base_authz_principal_roles.principal_id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();

        if ($userIds === []) {
            return [];
        }

        return User::query()->whereIn('id', $userIds)->orderBy('id')->get()->all();
    }

    /** The ISO year-week a reminder belongs to, e.g. 2026-W37. */
    public static function weekKey(DateTimeInterface $moment): string
    {
        return CarbonImmutable::instance($moment)->format('o-\WW');
    }

    public static function ageDays(): int
    {
        $configured = config(self::AGE_CONFIG);

        return is_int($configured) && $configured >= 0 ? $configured : self::DEFAULT_AGE_DAYS;
    }

    /** Whole days from $from to $to; negative when $to is earlier. */
    private static function daysBetween(CarbonImmutable $from, CarbonImmutable $to): int
    {
        return (int) round(($to->getTimestamp() - $from->getTimestamp()) / 86400);
    }

    private function moment(?DateTimeInterface $asOf): CarbonImmutable
    {
        return $asOf === null ? CarbonImmutable::now() : CarbonImmutable::instance($asOf);
    }
}
