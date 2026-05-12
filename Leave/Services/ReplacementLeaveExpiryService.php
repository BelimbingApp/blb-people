<?php

namespace App\Modules\People\Leave\Services;

use App\Modules\People\Leave\Models\LeaveBalanceLedgerEntry;
use DateTimeImmutable;

/**
 * Sweeps replacement-leave ledger entries whose `expires_on` has passed and
 * records a matching `expired` reversing entry. Idempotent: an entry already
 * paired with an expiry reversal is skipped.
 */
class ReplacementLeaveExpiryService
{
    public function __construct(
        private readonly LeaveBalanceLedgerService $ledger,
    ) {}

    /**
     * @return int Number of expiry entries written.
     */
    public function sweep(?DateTimeImmutable $asOf = null, bool $dryRun = false): int
    {
        $asOf = $asOf ?? new DateTimeImmutable('today');

        $candidates = LeaveBalanceLedgerEntry::query()
            ->whereIn('entry_type', [LeaveBalanceLedgerEntry::ENTRY_ACCRUAL, LeaveBalanceLedgerEntry::ENTRY_CARRIED_FORWARD])
            ->where('source_type', LeaveBalanceLedgerEntry::SOURCE_REPLACEMENT_EARN)
            ->whereNotNull('expires_on')
            ->where('expires_on', '<', $asOf->format('Y-m-d'))
            ->where('quantity', '>', 0)
            ->get();

        $written = 0;
        foreach ($candidates as $entry) {
            $alreadyExpired = LeaveBalanceLedgerEntry::query()
                ->where('entry_type', LeaveBalanceLedgerEntry::ENTRY_EXPIRED)
                ->where('source_type', LeaveBalanceLedgerEntry::SOURCE_REPLACEMENT_EXPIRY)
                ->where('source_id', $entry->getKey())
                ->exists();
            if ($alreadyExpired) {
                continue;
            }

            if (! $dryRun) {
                $this->ledger->record(
                    companyId: $entry->company_id,
                    employeeId: $entry->employee_id,
                    leaveTypeId: $entry->leave_type_id,
                    leaveYear: (int) $entry->expires_on->format('Y'),
                    entryType: LeaveBalanceLedgerEntry::ENTRY_EXPIRED,
                    quantity: -1.0 * (float) $entry->quantity,
                    unit: $entry->unit,
                    sourceType: LeaveBalanceLedgerEntry::SOURCE_REPLACEMENT_EXPIRY,
                    sourceId: $entry->getKey(),
                    packIdentifier: $entry->pack_identifier,
                    packVersion: $entry->pack_version,
                    occurredOn: $entry->expires_on,
                    note: 'Replacement leave expired',
                    metadata: ['original_ledger_entry_id' => $entry->getKey()],
                );
            }

            $written++;
        }

        return $written;
    }
}
