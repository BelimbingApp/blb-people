<?php

namespace App\Modules\People\Leave\Services;

use App\Modules\People\Leave\Models\LeaveBalanceLedgerEntry;
use App\Modules\People\Leave\Models\LeaveEntitlementPolicy;
use App\Modules\People\Leave\Models\LeaveRequest;
use App\Modules\People\Leave\Models\LeaveRequestPolicy;

/**
 * The single sanctioned writer of append-only leave ledger entries.
 *
 * Captures the policy version snapshots and pack identity so future
 * recomputations remain explainable.
 */
class LeaveBalanceLedgerService
{
    /** @param array<string, mixed> $metadata */
    public function record(
        int $companyId,
        int $employeeId,
        int $leaveTypeId,
        int $leaveYear,
        string $entryType,
        float $quantity,
        string $unit,
        string $sourceType,
        ?int $sourceId = null,
        ?LeaveEntitlementPolicy $entitlementPolicy = null,
        ?LeaveRequestPolicy $requestPolicy = null,
        ?string $packIdentifier = null,
        ?string $packVersion = null,
        ?\DateTimeInterface $occurredOn = null,
        ?\DateTimeInterface $expiresOn = null,
        ?int $recordedByUserId = null,
        ?string $note = null,
        array $metadata = [],
    ): LeaveBalanceLedgerEntry {
        return LeaveBalanceLedgerEntry::query()->create([
            'company_id' => $companyId,
            'employee_id' => $employeeId,
            'leave_type_id' => $leaveTypeId,
            'leave_year' => $leaveYear,
            'entry_type' => $entryType,
            'quantity' => $quantity,
            'unit' => $unit,
            'source_type' => $sourceType,
            'source_id' => $sourceId,
            'entitlement_policy_id' => $entitlementPolicy?->getKey(),
            'entitlement_policy_version' => $entitlementPolicy?->version,
            'request_policy_id' => $requestPolicy?->getKey(),
            'request_policy_version' => $requestPolicy?->version,
            'pack_identifier' => $packIdentifier,
            'pack_version' => $packVersion,
            'occurred_on' => $occurredOn ?? now(),
            'expires_on' => $expiresOn,
            'recorded_by_user_id' => $recordedByUserId,
            'note' => $note,
            'metadata' => $metadata,
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
