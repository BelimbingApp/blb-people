<?php

namespace App\Modules\People\Leave\Services;

use App\Modules\People\Leave\Exceptions\LeaveEncashmentException;
use App\Modules\People\Leave\Models\LeaveBalanceLedgerEntry;
use App\Modules\People\Leave\Models\LeaveType;
use App\Modules\People\Payroll\Contracts\Intake\PayrollContributionPayload;
use App\Modules\People\Payroll\Services\PayrollContributionIntake;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Encash a remaining annual-leave (or similar) balance: writes a debit
 * `encashed` ledger entry and hands the corresponding payroll contribution
 * to Payroll via the intake contract.
 */
class LeaveEncashmentService
{
    public const SOURCE_TYPE = 'leave_encashment';

    public function __construct(
        private readonly LeaveBalanceLedgerService $ledger,
        private readonly PayrollContributionIntake $intake,
    ) {}

    public function encash(
        int $companyId,
        int $employeeId,
        int $leaveTypeId,
        int $leaveYear,
        float $days,
        ?int $actorUserId = null,
        ?string $note = null,
        ?string $currency = 'MYR',
    ): LeaveBalanceLedgerEntry {
        if ($days <= 0.0) {
            throw LeaveEncashmentException::nonPositiveDays();
        }

        $available = $this->ledger->balanceFor($employeeId, $leaveTypeId, $leaveYear);
        if ($days > $available) {
            throw LeaveEncashmentException::insufficientBalance($days, $available);
        }

        return DB::transaction(function () use ($companyId, $employeeId, $leaveTypeId, $leaveYear, $days, $actorUserId, $note, $currency): LeaveBalanceLedgerEntry {
            $leaveType = LeaveType::query()->findOrFail($leaveTypeId);
            $now = new DateTimeImmutable('today');

            $entry = $this->ledger->record(
                companyId: $companyId,
                employeeId: $employeeId,
                leaveTypeId: $leaveTypeId,
                leaveYear: $leaveYear,
                entryType: LeaveBalanceLedgerEntry::ENTRY_ENCASHED,
                quantity: -1.0 * $days,
                unit: 'day',
                sourceType: LeaveBalanceLedgerEntry::SOURCE_MANUAL_ADJUSTMENT,
                packIdentifier: $leaveType->pack_identifier,
                packVersion: $leaveType->pack_version,
                occurredOn: now(),
                recordedByUserId: $actorUserId,
                note: $note ?? 'Leave encashment',
            );

            $this->intake->ingest(new PayrollContributionPayload(
                sourceType: self::SOURCE_TYPE,
                sourceId: (int) $entry->getKey(),
                payItemCode: LeaveType::PAYROLL_CODE_LEAVE_ENCASHMENT,
                periodAnchor: $now,
                companyId: $companyId,
                employeeId: $employeeId,
                currency: (string) ($currency ?? 'MYR'),
                occurredOn: $now,
                inputType: 'earning',
                amount: 0.0,
                quantity: $days,
                rate: null,
                label: $leaveType->name.' encashment',
                metadata: [
                    'leave_type_code' => $leaveType->code,
                    'leave_ledger_entry_id' => $entry->getKey(),
                    'leave_year' => $leaveYear,
                ],
            ));

            return $entry;
        });
    }
}
