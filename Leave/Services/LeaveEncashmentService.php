<?php

namespace App\Modules\People\Leave\Services;

use App\Modules\People\Leave\Data\LeaveEncashmentData;
use App\Modules\People\Leave\Data\LeaveLedgerEntryData;
use App\Modules\People\Leave\Data\LeaveLedgerEntryOptions;
use App\Modules\People\Leave\Data\LeaveLedgerEntrySource;
use App\Modules\People\Leave\Data\LeaveLedgerEntrySubject;
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

    public function encash(LeaveEncashmentData $data): LeaveBalanceLedgerEntry
    {
        $options = $data->options;

        if ($data->days <= 0.0) {
            throw LeaveEncashmentException::nonPositiveDays();
        }

        $available = $this->ledger->balanceFor($data->employeeId, $data->leaveTypeId, $data->leaveYear);
        if ($data->days > $available) {
            throw LeaveEncashmentException::insufficientBalance($data->days, $available);
        }

        return DB::transaction(function () use ($data, $options): LeaveBalanceLedgerEntry {
            $leaveType = LeaveType::query()->findOrFail($data->leaveTypeId);
            $now = new DateTimeImmutable('today');

            $entry = $this->ledger->record(new LeaveLedgerEntryData(
                subject: new LeaveLedgerEntrySubject(
                    companyId: $data->companyId,
                    employeeId: $data->employeeId,
                    leaveTypeId: $data->leaveTypeId,
                    leaveYear: $data->leaveYear,
                ),
                entryType: LeaveBalanceLedgerEntry::ENTRY_ENCASHED,
                quantity: -1.0 * $data->days,
                unit: 'day',
                source: new LeaveLedgerEntrySource(LeaveBalanceLedgerEntry::SOURCE_MANUAL_ADJUSTMENT),
                options: new LeaveLedgerEntryOptions(
                    packIdentifier: $leaveType->pack_identifier,
                    packVersion: $leaveType->pack_version,
                    occurredOn: now(),
                    recordedByUserId: $options?->actorUserId,
                    note: $options?->note ?? 'Leave encashment',
                ),
            ));

            event(new LeaveEncashed(
                companyId: $data->companyId,
                employeeId: $data->employeeId,
                leaveTypeId: $data->leaveTypeId,
                leaveBalanceLedgerEntryId: (int) $entry->getKey(),
                leaveYear: $data->leaveYear,
                occurredOn: $now,
                days: $data->days,
                currency: (string) ($options?->currency ?? 'MYR'),
            ));

            return $entry;
        });
    }
}
