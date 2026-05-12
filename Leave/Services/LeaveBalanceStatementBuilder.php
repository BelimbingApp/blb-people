<?php

namespace App\Modules\People\Leave\Services;

use App\Modules\People\Leave\Data\LeaveBalanceStatement;
use App\Modules\People\Leave\Data\LeaveBalanceStatementRow;
use App\Modules\People\Leave\Models\LeaveBalanceLedgerEntry;
use App\Modules\People\Leave\Models\LeaveType;

class LeaveBalanceStatementBuilder
{
    /**
     * Aggregates ledger entries into a per-leave-type balance statement.
     * Data shape is ready to hand to a Blade PDF template (Phase 6 follow-up).
     */
    public function build(int $employeeId, int $leaveYear, ?int $companyId = null): LeaveBalanceStatement
    {
        $query = LeaveBalanceLedgerEntry::query()
            ->where('employee_id', $employeeId)
            ->where('leave_year', $leaveYear);

        if ($companyId !== null) {
            $query->where('company_id', $companyId);
        }

        $entries = $query->get();
        $rows = [];

        $byType = $entries->groupBy('leave_type_id');
        $leaveTypes = LeaveType::query()->whereIn('id', $byType->keys()->all())->get()->keyBy('id');

        foreach ($byType as $leaveTypeId => $group) {
            $totals = [
                LeaveBalanceLedgerEntry::ENTRY_OPENING => 0.0,
                LeaveBalanceLedgerEntry::ENTRY_ACCRUAL => 0.0,
                LeaveBalanceLedgerEntry::ENTRY_TAKEN => 0.0,
                LeaveBalanceLedgerEntry::ENTRY_CANCELLED => 0.0,
                LeaveBalanceLedgerEntry::ENTRY_ADJUSTED => 0.0,
                LeaveBalanceLedgerEntry::ENTRY_CARRIED_FORWARD => 0.0,
                LeaveBalanceLedgerEntry::ENTRY_EXPIRED => 0.0,
                LeaveBalanceLedgerEntry::ENTRY_ENCASHED => 0.0,
            ];

            foreach ($group as $entry) {
                $totals[$entry->entry_type] = ($totals[$entry->entry_type] ?? 0.0) + (float) $entry->quantity;
            }

            $balance = array_sum($totals);
            $type = $leaveTypes->get($leaveTypeId);

            $rows[] = new LeaveBalanceStatementRow(
                leaveTypeId: $leaveTypeId,
                leaveTypeCode: $type?->code ?? 'unknown',
                leaveTypeName: $type?->name ?? '(deleted)',
                opening: $totals[LeaveBalanceLedgerEntry::ENTRY_OPENING],
                accrued: $totals[LeaveBalanceLedgerEntry::ENTRY_ACCRUAL],
                taken: abs($totals[LeaveBalanceLedgerEntry::ENTRY_TAKEN]),
                cancelled: $totals[LeaveBalanceLedgerEntry::ENTRY_CANCELLED],
                adjusted: $totals[LeaveBalanceLedgerEntry::ENTRY_ADJUSTED],
                carriedForward: $totals[LeaveBalanceLedgerEntry::ENTRY_CARRIED_FORWARD],
                expired: abs($totals[LeaveBalanceLedgerEntry::ENTRY_EXPIRED]),
                encashed: abs($totals[LeaveBalanceLedgerEntry::ENTRY_ENCASHED]),
                balance: $balance,
                totalsByEntryType: $totals,
            );
        }

        return new LeaveBalanceStatement(
            employeeId: $employeeId,
            leaveYear: $leaveYear,
            rows: $rows,
            metadata: [
                'generated_at' => now()->toIso8601String(),
                'entry_count' => $entries->count(),
            ],
        );
    }
}
