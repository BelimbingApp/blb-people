<?php

namespace App\Modules\People\Payroll\Contracts\Intake;

use DateTimeImmutable;
use DateTimeInterface;

/**
 * Neutral value object describing one atomic payroll contribution coming from a
 * producer module (Claim, Leave, Attendance, ...).
 *
 * Producers depend on this class and on PayrollContributionIntake — never on
 * PayrollInput, PayrollRun, or PayrollRunParticipant.
 *
 * Identity is the composite tuple (source_type, source_id, pay_item_code,
 * period_anchor). Re-ingesting the same tuple is idempotent.
 */
final readonly class PayrollContributionPayload
{
    /**
     * @param  array<string, mixed>  $accountingSnapshot
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public string $sourceType,
        public int $sourceId,
        public string $payItemCode,
        public DateTimeImmutable $periodAnchor,
        public int $companyId,
        public int $employeeId,
        public string $currency,
        public DateTimeImmutable $occurredOn,
        public string $inputType,
        public ?float $amount,
        public ?float $quantity,
        public ?float $rate,
        public string $label,
        public array $accountingSnapshot = [],
        public array $metadata = [],
        public ?string $idempotencyKey = null,
    ) {}

    public function idempotencyKey(): string
    {
        return $this->idempotencyKey
            ?? sprintf(
                '%s:%d:%s:%s',
                $this->sourceType,
                $this->sourceId,
                $this->payItemCode,
                $this->periodAnchor->format('Y-m-d'),
            );
    }

    public static function periodAnchorOf(DateTimeInterface $date): DateTimeImmutable
    {
        return $date instanceof DateTimeImmutable
            ? $date
            : DateTimeImmutable::createFromInterface($date);
    }
}
