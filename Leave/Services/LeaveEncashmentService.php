<?php

namespace App\Modules\People\Leave\Services;

use App\Modules\People\Leave\Models\LeaveBalanceLedgerEntry;
use App\Modules\People\Leave\Models\LeaveType;
use App\Modules\People\Payroll\Models\PayrollInput;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Encash a remaining annual-leave (or similar) balance: writes a debit
 * `encashed` ledger entry and produces a matching PayrollInput line for
 * the next draft run.
 */
class LeaveEncashmentService
{
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
            throw new RuntimeException('Encashment days must be positive.');
        }

        $available = $this->ledger->balanceFor($employeeId, $leaveTypeId, $leaveYear);
        if ($days > $available) {
            throw new RuntimeException(sprintf(
                'Cannot encash %.2f days; available balance is %.2f days.',
                $days,
                $available,
            ));
        }

        return DB::transaction(function () use ($companyId, $employeeId, $leaveTypeId, $leaveYear, $days, $actorUserId, $note, $currency): LeaveBalanceLedgerEntry {
            $leaveType = LeaveType::query()->findOrFail($leaveTypeId);

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

            PayrollInput::query()->create([
                'employee_id' => $employeeId,
                'source_type' => 'leave_encashment',
                'source_id' => $entry->getKey(),
                'pay_item_code' => LeaveType::PAYROLL_CODE_LEAVE_ENCASHMENT,
                'label' => $leaveType->name.' encashment',
                'input_type' => PayrollInput::TYPE_EARNING,
                'quantity' => $days,
                'amount' => 0,
                'currency' => $currency,
                'occurred_on' => now(),
                'metadata' => [
                    'leave_type_code' => $leaveType->code,
                    'leave_ledger_entry_id' => $entry->getKey(),
                ],
            ]);

            return $entry;
        });
    }
}
