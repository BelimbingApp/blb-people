<?php

namespace App\Domains\People\Skills\Services;

use App\Base\Tenancy\Contracts\TenantContext;
use App\Core\User\Models\User;
use App\Domains\People\Provider\Enums\WorkforceResourceType;
use App\Domains\People\Skills\Data\DeliveryRunResult;
use App\Domains\People\Skills\Data\DueReminder;
use App\Domains\People\Skills\Enums\ReminderDeliveryState;
use App\Domains\People\Skills\Enums\ReminderRule;
use App\Domains\People\Skills\Models\SkillReminderDelivery;
use App\Domains\People\Skills\Notifications\SkillReminderNotification;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Tell somebody what ReminderRules found, once per ISO week, and remember it.
 *
 * The ledger row is written before notify() is called, in `failed` state with
 * the failure "not attempted". That order is the whole design: the unique key
 * on (reminder, recipient, period) makes a second run in the same week lose
 * the insert rather than send a second message, and a crash between the insert
 * and the send leaves a visible failed row for retry() instead of a silent
 * gap. A reminder nobody can be found for writes nothing: a row with no
 * recipient is not a delivery, it is a report, and the counts carry it.
 */
final class ReminderDeliveries
{
    public const NOT_ATTEMPTED = 'not attempted';

    public const SKIP_ALREADY_DELIVERED = 'already delivered this period';

    public const SKIP_NO_HEAD_USER = 'employee has no head of department with a user';

    public const SKIP_NO_OWNER_USER = 'action owner has no user';

    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly ReminderRules $rules,
        private readonly DepartmentHeads $heads,
        private readonly WorkforceSubjects $subjects,
    ) {}

    public function send(
        int $companyEntityId,
        ?DateTimeInterface $asOf = null,
        int $expiringWithinDays = ReminderRules::DEFAULT_EXPIRING_WITHIN_DAYS,
    ): DeliveryRunResult {
        return $this->run($companyEntityId, $asOf, $expiringWithinDays, write: true);
    }

    /** What send() would do right now, writing and sending nothing. */
    public function preview(
        int $companyEntityId,
        ?DateTimeInterface $asOf = null,
        int $expiringWithinDays = ReminderRules::DEFAULT_EXPIRING_WITHIN_DAYS,
    ): DeliveryRunResult {
        return $this->run($companyEntityId, $asOf, $expiringWithinDays, write: false);
    }

    /**
     * Re-attempt this period's failed rows. A sent row is never touched: the
     * ledger says the recipient already has the message, and the ledger wins.
     */
    public function retry(int $companyEntityId, ?DateTimeInterface $asOf = null): DeliveryRunResult
    {
        $tenantId = $this->tenantContext->requireTenantId();
        $now = $this->moment($asOf);

        $failed = SkillReminderDelivery::query()
            ->forCompany($tenantId, $companyEntityId)
            ->where('state', ReminderDeliveryState::Failed->value)
            ->where('period_key', self::periodKey($now))
            ->orderBy('id')
            ->get();

        $sent = 0;
        $failures = [];

        foreach ($failed as $row) {
            $reminder = new DueReminder(
                companyEntityId: (int) $row->company_entity_id,
                employeeEntityId: (int) $row->employee_entity_id,
                skillId: (int) $row->skill_id,
                rule: $row->rule,
                dueOn: CarbonImmutable::instance($row->due_on),
                requirementReference: '',
                requirementVersion: 0,
                developmentActionId: $row->developmentActionId(),
            );

            if ($this->deliver($row, $reminder, $now)) {
                $sent++;
            } else {
                $failures[] = $row;
            }
        }

        return new DeliveryRunResult(sent: $sent, skipped: 0, failed: count($failures), failures: $failures);
    }

    /** The ISO year-week a delivery belongs to, e.g. 2026-W37. */
    public static function periodKey(DateTimeInterface $moment): string
    {
        return CarbonImmutable::instance($moment)->format('o-\WW');
    }

    private function run(int $companyEntityId, ?DateTimeInterface $asOf, int $expiringWithinDays, bool $write): DeliveryRunResult
    {
        $tenantId = $this->tenantContext->requireTenantId();
        $now = $this->moment($asOf);
        $periodKey = self::periodKey($now);

        $sent = 0;
        $failures = [];
        $skips = [];

        foreach ($this->rules->due($companyEntityId, $now, $expiringWithinDays) as $reminder) {
            [$recipientUserId, $reason] = $this->recipientOf($reminder);

            if ($recipientUserId === null) {
                $skips[$reason] = ($skips[$reason] ?? 0) + 1;

                continue;
            }

            if (! $write) {
                $already = SkillReminderDelivery::query()
                    ->forCompany($tenantId, $companyEntityId)
                    ->where('rule', $reminder->rule->value)
                    ->where('employee_entity_id', $reminder->employeeEntityId)
                    ->where('skill_id', $reminder->skillId)
                    ->where('development_action_id', $reminder->developmentActionId ?? SkillReminderDelivery::NO_ACTION)
                    ->where('period_key', $periodKey)
                    ->where('recipient_user_id', $recipientUserId)
                    ->exists();

                if ($already) {
                    $skips[self::SKIP_ALREADY_DELIVERED] = ($skips[self::SKIP_ALREADY_DELIVERED] ?? 0) + 1;
                } else {
                    $sent++;
                }

                continue;
            }

            $row = $this->claim($tenantId, $reminder, $periodKey, $recipientUserId, $now);

            if ($row === null) {
                $skips[self::SKIP_ALREADY_DELIVERED] = ($skips[self::SKIP_ALREADY_DELIVERED] ?? 0) + 1;

                continue;
            }

            if ($this->deliver($row, $reminder, $now)) {
                $sent++;
            } else {
                $failures[] = $row;
            }
        }

        return new DeliveryRunResult(
            sent: $sent,
            skipped: array_sum($skips),
            failed: count($failures),
            failures: $failures,
            skipReasons: $skips,
        );
    }

    /**
     * Null when this recipient already holds a row for this reminder and
     * period. The insert runs in its own transaction so a unique violation
     * aborts only itself, not a caller's enclosing transaction (PostgreSQL
     * poisons the outer one otherwise).
     */
    private function claim(int $tenantId, DueReminder $reminder, string $periodKey, int $recipientUserId, CarbonImmutable $now): ?SkillReminderDelivery
    {
        try {
            return DB::transaction(static fn (): SkillReminderDelivery => SkillReminderDelivery::query()->create([
                'tenant_id' => $tenantId,
                'company_entity_id' => $reminder->companyEntityId,
                'rule' => $reminder->rule,
                'employee_entity_id' => $reminder->employeeEntityId,
                'skill_id' => $reminder->skillId,
                'development_action_id' => $reminder->developmentActionId ?? SkillReminderDelivery::NO_ACTION,
                'due_on' => $reminder->dueOn->format('Y-m-d'),
                'period_key' => $periodKey,
                'recipient_user_id' => $recipientUserId,
                'state' => ReminderDeliveryState::Failed,
                'failure' => self::NOT_ATTEMPTED,
                'attempted_at' => $now,
            ]));
        } catch (UniqueConstraintViolationException) {
            return null;
        }
    }

    /** True when the recipient now holds the message; false leaves the row failed with the reason. */
    private function deliver(SkillReminderDelivery $row, DueReminder $reminder, CarbonImmutable $now): bool
    {
        $recipient = User::query()->find((int) $row->recipient_user_id);

        try {
            if ($recipient === null) {
                throw new \RuntimeException('recipient user '.$row->recipient_user_id.' no longer exists');
            }

            $recipient->notify(new SkillReminderNotification($reminder, $this->urlFor($reminder), (string) $row->period_key));
        } catch (\Throwable $exception) {
            $row->update([
                'state' => ReminderDeliveryState::Failed,
                'failure' => mb_substr($exception->getMessage(), 0, 2000),
                'attempted_at' => $now,
            ]);

            return false;
        }

        $row->update([
            'state' => ReminderDeliveryState::Sent,
            'failure' => null,
            'attempted_at' => $now,
            'sent_at' => $now,
        ]);

        return true;
    }

    /**
     * Who is told. An overdue action goes to its accountable owner; a score
     * reminder goes to the employee's head of department, who owns the
     * monthly review that acts on it.
     *
     * @return array{0: ?int, 1: string}
     */
    private function recipientOf(DueReminder $reminder): array
    {
        if ($reminder->rule === ReminderRule::OverdueDevelopmentAction) {
            $owner = $reminder->ownerEmployeeEntityId === null ? null : $this->subjects->resolve(
                $this->tenantContext->requireTenantId(),
                $reminder->companyEntityId,
                WorkforceResourceType::Employee,
                $reminder->ownerEmployeeEntityId,
            );
            $userId = $owner?->userReference?->externalId;

            return [$userId === null ? null : (int) $userId, self::SKIP_NO_OWNER_USER];
        }

        return [$this->heads->headUserOf($reminder->companyEntityId, $reminder->employeeEntityId), self::SKIP_NO_HEAD_USER];
    }

    private function urlFor(DueReminder $reminder): string
    {
        if ($reminder->developmentActionId !== null) {
            return route('people.skill.development-actions.index', ['action' => $reminder->developmentActionId]);
        }

        return route('people.skill.assessment.matrix', ['employee' => $reminder->employeeEntityId]);
    }

    private function moment(?DateTimeInterface $asOf): CarbonImmutable
    {
        return $asOf === null ? CarbonImmutable::now() : CarbonImmutable::instance($asOf);
    }
}
