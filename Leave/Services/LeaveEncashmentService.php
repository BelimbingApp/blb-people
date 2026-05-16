<?php

namespace App\Modules\People\Leave\Services;

use App\Modules\People\Leave\Events\LeaveEncashed;
use App\Modules\People\Leave\Exceptions\LeaveEncashmentException;
use App\Modules\People\Leave\Models\LeaveBalanceLedgerEntry;
use App\Modules\People\Leave\Models\LeaveType;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Encash a remaining annual-leave (or similar) balance: writes a debit
 * `encashed` ledger entry and dispatches a LeaveEncashed event so
 * downstream consumers can record the payout.
 */
class LeaveEncashmentService
{
    public const SOURCE_TYPE = 'leave_encashment';

    public function __construct(
        private readonly LeaveBalanceLedgerService $ledger,
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

            event(new LeaveEncashed(
                companyId: $companyId,
                employeeId: $employeeId,
                leaveTypeId: $leaveTypeId,
                leaveBalanceLedgerEntryId: (int) $entry->getKey(),
                leaveYear: $leaveYear,
                occurredOn: $now,
                days: $days,
                currency: (string) ($currency ?? 'MYR'),
            ));

            return $entry;
        });
    }
}
