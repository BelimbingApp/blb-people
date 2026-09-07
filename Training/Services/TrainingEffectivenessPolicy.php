<?php

namespace App\Domains\People\Training\Services;

use App\Base\Tenancy\Contracts\TenantContext;
use App\Core\User\Models\User;
use App\Domains\People\Skills\Services\CompanyAttribution;
use App\Domains\People\Skills\Services\SkillAudience;
use App\Domains\People\Training\Enums\EffectivenessCheckpoint;
use App\Domains\People\Training\Exceptions\InvalidTrainingEffectivenessException;
use App\Domains\People\Training\Models\TrainingEffectivenessCheckpointPolicy;
use Carbon\CarbonImmutable;
use DateTimeInterface;

/**
 * How many days after an event ends each effectiveness checkpoint opens, for
 * one company, as governed policy rather than calendar arithmetic in code.
 *
 * docs/contracts/training-effectiveness.md: the 30/60/90-day intervals are
 * company/workbook defaults, and governed policy settles the trigger date. A
 * company that has never set a policy keeps the workbook defaults from
 * Training/Config/training.php.
 *
 * The policy in force when the event ended is the one used for that event
 * forever. Changing the offsets never re-dates the checkpoints of an event
 * that has already ended: a thirty-day question that opened, was asked and was
 * answered cannot retroactively have been due on a different day, and an
 * answer rate computed over moving denominators is not a measurement. That is
 * why rows are append-only in the database as well as here, and why set() is
 * prospective.
 */
final class TrainingEffectivenessPolicy
{
    /** Setting the offsets is HR governance, not part of reviewing. */
    public const MANAGE_CAPABILITY = 'people.training.effectiveness-policy.manage';

    public function __construct(
        private readonly TenantContext $tenancy,
        private readonly CompanyAttribution $companies,
        private readonly SkillAudience $audiences,
    ) {}

    /**
     * The offsets that govern an event which ended when it did.
     *
     * @return array{day_30: int, day_60: int, day_90: int}
     */
    public function offsetsFor(int $tenantId, int $companyEntityId, DateTimeInterface $eventEndedAt): array
    {
        $row = TrainingEffectivenessCheckpointPolicy::query()
            ->forCompany($tenantId, $companyEntityId)
            // whereDate, not a bare compare: effective_from is a date column
            // and the event end carries a time of day, so comparing the two as
            // strings would silently never match on SQLite.
            ->whereDate('effective_from', '<=', CarbonImmutable::instance($eventEndedAt)->toDateString())
            ->orderByDesc('effective_from')
            ->orderByDesc('id')
            ->first();

        if ($row === null) {
            return EffectivenessCheckpoint::defaultOffsets();
        }

        return [
            EffectivenessCheckpoint::Day30->value => (int) $row->day_30_offset,
            EffectivenessCheckpoint::Day60->value => (int) $row->day_60_offset,
            EffectivenessCheckpoint::Day90->value => (int) $row->day_90_offset,
        ];
    }

    /**
     * Every policy this company has had, newest first.
     *
     * @return list<TrainingEffectivenessCheckpointPolicy>
     */
    public function history(int $tenantId, int $companyEntityId): array
    {
        return TrainingEffectivenessCheckpointPolicy::query()
            ->forCompany($tenantId, $companyEntityId)
            ->orderByDesc('effective_from')
            ->orderByDesc('id')
            ->get()
            ->all();
    }

    /**
     * Append a company's next policy. Prospective, reasoned, and never an edit.
     */
    public function set(
        User $actor,
        int $companyEntityId,
        int $day30,
        int $day60,
        int $day90,
        DateTimeInterface $effectiveFrom,
        string $reason,
    ): TrainingEffectivenessCheckpointPolicy {
        $tenantId = $this->scope($actor, $companyEntityId);
        $this->authorize($actor, self::MANAGE_CAPABILITY);

        foreach ([$day30, $day60, $day90] as $offset) {
            if ($offset < 1) {
                throw new InvalidTrainingEffectivenessException(
                    'A checkpoint opens at least one day after the event ends.',
                );
            }
        }
        if ($day30 >= $day60 || $day60 >= $day90) {
            throw new InvalidTrainingEffectivenessException(
                'Checkpoint offsets must be strictly increasing; each stage asks a later question than the last.',
            );
        }
        if (trim($reason) === '') {
            throw new InvalidTrainingEffectivenessException('A checkpoint policy needs a stated reason.');
        }

        // CarbonImmutable::today() honours Carbon::setTestNow, which
        // new DateTimeImmutable('today') does not.
        $from = CarbonImmutable::instance($effectiveFrom)->startOfDay();

        if ($from->lessThan(CarbonImmutable::today())) {
            throw new InvalidTrainingEffectivenessException(
                'A checkpoint policy is prospective: it cannot take effect before today.',
            );
        }

        return TrainingEffectivenessCheckpointPolicy::query()->create([
            'tenant_id' => $tenantId,
            'company_entity_id' => $companyEntityId,
            'day_30_offset' => $day30,
            'day_60_offset' => $day60,
            'day_90_offset' => $day90,
            'effective_from' => $from->toDateString(),
            'set_by_user_id' => $actor->getKey(),
            'reason' => trim($reason),
        ]);
    }

    private function scope(User $actor, int $companyEntityId): int
    {
        $tenantId = $this->tenancy->currentTenantId();

        if ($tenantId === null) {
            throw new InvalidTrainingEffectivenessException('A tenant context is required for checkpoint policy.');
        }
        if ((int) $actor->tenant_id !== $tenantId || ! $this->companies->mayActFor($actor, $companyEntityId)) {
            throw new InvalidTrainingEffectivenessException(
                'The checkpoint policy is unavailable in the current company scope.',
            );
        }

        return $tenantId;
    }

    private function authorize(User $actor, string $capability): void
    {
        try {
            $audiences = $this->audiences->authorizeAudience($actor, $capability);
        } catch (\Throwable) {
            throw new InvalidTrainingEffectivenessException(
                'Only HR may set the training effectiveness checkpoint policy.',
            );
        }
        if (! in_array(SkillAudience::HR, $audiences, true)) {
            throw new InvalidTrainingEffectivenessException(
                'Only HR may set the training effectiveness checkpoint policy.',
            );
        }
    }
}
