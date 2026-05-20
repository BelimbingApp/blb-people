<?php

namespace App\Modules\People\Leave\Services;

use App\Modules\People\Leave\Data\LeaveLedgerEntryData;
use App\Modules\People\Leave\Models\LeaveBalanceLedgerEntry;
use App\Modules\People\Leave\Models\LeaveRequest;

/**
 * The single sanctioned writer of append-only leave ledger entries.
 *
 * Captures the policy version snapshots and pack identity so future
 * recomputations remain explainable.
 */
class LeaveBalanceLedgerService
{
    public function record(LeaveLedgerEntryData $entry): LeaveBalanceLedgerEntry
    {
        $policy = $entry->policy;
        $options = $entry->options;

        return LeaveBalanceLedgerEntry::query()->create([
            'company_id' => $entry->subject->companyId,
            'employee_id' => $entry->subject->employeeId,
            'leave_type_id' => $entry->subject->leaveTypeId,
            'leave_year' => $entry->subject->leaveYear,
            'entry_type' => $entry->entryType,
            'quantity' => $entry->quantity,
            'unit' => $entry->unit,
            'source_type' => $entry->source->type,
            'source_id' => $entry->source->id,
            'entitlement_policy_id' => $policy?->entitlement?->getKey(),
            'entitlement_policy_version' => $policy?->entitlement?->version,
            'request_policy_id' => $policy?->request?->getKey(),
            'request_policy_version' => $policy?->request?->version,
            'pack_identifier' => $options?->packIdentifier,
            'pack_version' => $options?->packVersion,
            'occurred_on' => $options?->occurredOn ?? now(),
            'expires_on' => $options?->expiresOn,
            'recorded_by_user_id' => $options?->recordedByUserId,
            'note' => $options?->note,
            'metadata' => $options?->metadata ?? [],
        ]);
    }

    public function balanceFor(int $employeeId, int $leaveTypeId, int $leaveYear): float
    {
        return (float) LeaveBalanceLedgerEntry::query()
            ->where('employee_id', $employeeId)
            ->where('leave_type_id', $leaveTypeId)
            ->where('leave_year', $leaveYear)
            ->sum('quantity');
    }

    /**
     * Consumed = approved/applied 'taken' entries (negative quantities counted as positive consumption).
     */
    public function consumedFor(int $employeeId, int $leaveTypeId, int $leaveYear): float
    {
        return (float) abs(LeaveBalanceLedgerEntry::query()
            ->where('employee_id', $employeeId)
            ->where('leave_type_id', $leaveTypeId)
            ->where('leave_year', $leaveYear)
            ->where('entry_type', LeaveBalanceLedgerEntry::ENTRY_TAKEN)
            ->sum('quantity'));
    }

    /**
     * Encumbered = consumed balance plus active submitted/approved requests
     * that have not yet been applied to the ledger.
     */
    public function encumberedFor(int $employeeId, int $leaveTypeId, int $leaveYear): float
    {
        $consumed = $this->consumedFor($employeeId, $leaveTypeId, $leaveYear);

        $pending = (float) LeaveRequest::query()
            ->where('employee_id', $employeeId)
            ->where('leave_type_id', $leaveTypeId)
            ->whereYear('starts_on', $leaveYear)
            ->whereIn('status', [
                LeaveRequest::STATUS_SUBMITTED,
                LeaveRequest::STATUS_APPROVED,
            ])
            ->sum('quantity');

        return $consumed + $pending;
    }
}
