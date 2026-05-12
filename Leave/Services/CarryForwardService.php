<?php

namespace App\Modules\People\Leave\Services;

use App\Modules\People\Leave\Data\CarryForwardOutcome;
use App\Modules\People\Leave\Models\LeaveBalanceLedgerEntry;
use App\Modules\People\Leave\Models\LeaveEntitlementPolicy;
use App\Modules\People\Leave\Models\LeaveType;
use Illuminate\Support\Facades\DB;

/**
 * Year-end carry-forward computation for one (employee, leave_type, year).
 *
 * In dry-run mode returns the computed outcome without writing ledger entries;
 * otherwise writes a single `carried_forward` (credit, year+1) entry and a
 * matching `expired` (debit, current year) entry so the from-year nets to zero
 * for amounts beyond the cap.
 */
class CarryForwardService
{
    public function __construct(
        private readonly LeaveBalanceLedgerService $ledger,
    ) {}

    public function compute(
        int $companyId,
        int $employeeId,
        int $leaveTypeId,
        int $fromYear,
        LeaveEntitlementPolicy $policy,
        bool $dryRun = false,
    ): CarryForwardOutcome {
        $remaining = $this->ledger->balanceFor($employeeId, $leaveTypeId, $fromYear);

        $cap = (float) ($policy->bring_forward_cap_days ?? 0.0);
        $carried = max(0.0, min($remaining, $cap));
        $expired = max(0.0, $remaining - $carried);

        $outcome = new CarryForwardOutcome(
            employeeId: $employeeId,
            leaveTypeId: $leaveTypeId,
            fromYear: $fromYear,
            toYear: $fromYear + 1,
            remainingBalance: $remaining,
            capDays: $cap,
            carriedForward: $carried,
            expiredAtYearEnd: $expired,
            expiryMonth: $policy->bring_forward_expiry_month,
            anchor: $policy->bring_forward_anchor ?? LeaveEntitlementPolicy::ANCHOR_YEAR_START,
        );

        if ($dryRun || ($carried === 0.0 && $expired === 0.0)) {
            return $outcome;
        }

        DB::transaction(function () use ($outcome, $companyId, $employeeId, $leaveTypeId, $fromYear, $policy): void {
            $leaveType = LeaveType::query()->find($leaveTypeId);
            $expiresOn = $outcome->expiryMonth !== null
                ? \DateTimeImmutable::createFromFormat('Y-n-j', sprintf('%d-%d-1', $outcome->toYear, $outcome->expiryMonth))
                : null;

            if ($outcome->carriedForward > 0.0) {
                $this->ledger->record(
                    companyId: $companyId,
                    employeeId: $employeeId,
                    leaveTypeId: $leaveTypeId,
                    leaveYear: $outcome->toYear,
                    entryType: LeaveBalanceLedgerEntry::ENTRY_CARRIED_FORWARD,
                    quantity: $outcome->carriedForward,
                    unit: 'day',
                    sourceType: LeaveBalanceLedgerEntry::SOURCE_CARRY_FORWARD_JOB,
                    sourceId: null,
                    entitlementPolicy: $policy,
                    packIdentifier: $leaveType?->pack_identifier,
                    packVersion: $leaveType?->pack_version,
                    occurredOn: new \DateTimeImmutable($outcome->toYear.'-01-01'),
                    expiresOn: $expiresOn instanceof \DateTimeImmutable ? $expiresOn : null,
                    note: sprintf('Carried forward from %d (cap %.2f)', $fromYear, $outcome->capDays),
                    metadata: ['cap_days' => $outcome->capDays, 'anchor' => $outcome->anchor],
                );
            }

            if ($outcome->expiredAtYearEnd > 0.0) {
                $this->ledger->record(
                    companyId: $companyId,
                    employeeId: $employeeId,
                    leaveTypeId: $leaveTypeId,
                    leaveYear: $fromYear,
                    entryType: LeaveBalanceLedgerEntry::ENTRY_EXPIRED,
                    quantity: -1.0 * $outcome->expiredAtYearEnd,
                    unit: 'day',
                    sourceType: LeaveBalanceLedgerEntry::SOURCE_CARRY_FORWARD_JOB,
                    entitlementPolicy: $policy,
                    packIdentifier: $leaveType?->pack_identifier,
                    packVersion: $leaveType?->pack_version,
                    occurredOn: new \DateTimeImmutable($fromYear.'-12-31'),
                    note: sprintf('Expired at year-end (above cap of %.2f days)', $outcome->capDays),
                    metadata: ['cap_days' => $outcome->capDays],
                );
            }
        });

        return $outcome;
    }
}
