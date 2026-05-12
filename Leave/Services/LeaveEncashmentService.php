<?php

namespace App\Modules\People\Leave\Services;

use App\Modules\People\Leave\Models\LeaveBalanceLedgerEntry;
use App\Modules\People\Leave\Models\LeaveType;
use App\Modules\People\Payroll\Models\PayrollInput;
use App\Modules\People\Payroll\Models\PayrollRun;
use App\Modules\People\Payroll\Models\PayrollRunParticipant;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Encash a remaining annual-leave (or similar) balance: writes a debit
 * `encashed` ledger entry and produces a matching PayrollInput line for
 * the next draft run.
 */
class LeaveEncashmentService
{
    private const OPEN_RUN_STATUSES = [
        PayrollRun::STATUS_DRAFT,
        PayrollRun::STATUS_CALCULATED,
    ];

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
            $run = $this->findOpenRunFor($companyId);

            if ($run === null) {
                throw new RuntimeException(sprintf(
                    'Cannot encash leave for company %d without an open payroll run.',
                    $companyId,
                ));
            }

            $participant = $this->ensureParticipant($run, $employeeId);

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
                'payroll_run_id' => $run->getKey(),
                'payroll_run_participant_id' => $participant->getKey(),
                'employee_id' => $employeeId,
                'source_type' => 'leave_encashment',
                'source_id' => $entry->getKey(),
                'pay_item_code' => LeaveType::PAYROLL_CODE_LEAVE_ENCASHMENT,
                'label' => $leaveType->name.' encashment',
                'input_type' => PayrollInput::TYPE_EARNING,
                'quantity' => $days,
                'amount' => 0,
                'currency' => $run->currency ?: $currency,
                'occurred_on' => now(),
                'metadata' => [
                    'leave_type_code' => $leaveType->code,
                    'leave_ledger_entry_id' => $entry->getKey(),
                ],
            ]);

            return $entry;
        });
    }

    private function findOpenRunFor(int $companyId): ?PayrollRun
    {
        return PayrollRun::query()
            ->where('company_id', $companyId)
            ->whereIn('status', self::OPEN_RUN_STATUSES)
            ->orderBy('id')
            ->first();
    }

    private function ensureParticipant(PayrollRun $run, int $employeeId): PayrollRunParticipant
    {
        $participant = PayrollRunParticipant::query()
            ->where('payroll_run_id', $run->getKey())
            ->where('employee_id', $employeeId)
            ->first();

        if ($participant !== null) {
            return $participant;
        }

        return PayrollRunParticipant::query()->create([
            'payroll_run_id' => $run->getKey(),
            'company_id' => $run->company_id,
            'employee_id' => $employeeId,
            'status' => 'included',
            'currency' => $run->currency,
        ]);
    }
}
