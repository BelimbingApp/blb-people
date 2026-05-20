<?php

namespace App\Modules\People\Leave\Livewire\Concerns;

use App\Modules\Core\Employee\Models\Employee;
use App\Modules\People\Leave\Models\LeaveBalanceLedgerEntry;
use App\Modules\People\Leave\Models\LeaveEntitlementPolicy;
use App\Modules\People\Leave\Models\LeaveType;
use App\Modules\People\Leave\Services\CarryForwardService;
use App\Modules\People\Leave\Services\LeaveBalanceLedgerService;
use App\Modules\People\Leave\Services\LeaveCountryPackRegistry;
use DateTimeImmutable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Throwable;

trait HasLeaveBalanceActions
{
    public function recordAdjustment(): void
    {
        $this->authorizeManage();

        $companyId = $this->companyId();

        $validated = $this->validate([
            'adjustmentEmployeeId' => ['required', 'integer', Rule::exists(Employee::class, 'id')->where('company_id', $companyId)],
            'adjustmentLeaveTypeId' => ['required', 'integer', Rule::exists(LeaveType::class, 'id')->where('company_id', $companyId)],
            'adjustmentEntryType' => ['required', Rule::in([
                LeaveBalanceLedgerEntry::ENTRY_OPENING,
                LeaveBalanceLedgerEntry::ENTRY_ADJUSTED,
                LeaveBalanceLedgerEntry::ENTRY_ACCRUAL,
            ])],
            'adjustmentQuantity' => ['required', 'numeric'],
            'adjustmentUnit' => ['required', Rule::in(['day', 'hour'])],
            'adjustmentYear' => ['required', 'integer'],
            'adjustmentNote' => ['nullable', 'string', 'max:255'],
        ]);

        try {
            $leaveType = LeaveType::query()->find((int) $validated['adjustmentLeaveTypeId']);

            app(LeaveBalanceLedgerService::class)->record(
                companyId: $companyId,
                employeeId: (int) $validated['adjustmentEmployeeId'],
                leaveTypeId: (int) $validated['adjustmentLeaveTypeId'],
                leaveYear: (int) $validated['adjustmentYear'],
                entryType: $validated['adjustmentEntryType'],
                quantity: (float) $validated['adjustmentQuantity'],
                unit: $validated['adjustmentUnit'],
                sourceType: LeaveBalanceLedgerEntry::SOURCE_MANUAL_ADJUSTMENT,
                packIdentifier: $leaveType?->pack_identifier,
                packVersion: $leaveType?->pack_version,
                occurredOn: new DateTimeImmutable(sprintf('%d-01-01', (int) $validated['adjustmentYear'])),
                recordedByUserId: Auth::id(),
                note: $validated['adjustmentNote'] ?: null,
                metadata: ['source' => 'leave-workbench'],
            );

            $this->reset('adjustmentQuantity', 'adjustmentNote');
            $this->showAdjustmentModal = false;
            session()->flash('success', __('Ledger adjustment recorded.'));
        } catch (Throwable $e) {
            session()->flash('error', $e->getMessage());
        }
    }

    public function previewCarryForward(): void
    {
        $this->authorizeManage();

        $companyId = $this->companyId();
        $service = app(CarryForwardService::class);

        $query = LeaveBalanceLedgerEntry::query()
            ->where('company_id', $companyId)
            ->where('leave_year', $this->carryForwardFromYear)
            ->select('employee_id', 'leave_type_id')
            ->distinct();

        if ($this->carryForwardEmployeeId !== '' && ctype_digit($this->carryForwardEmployeeId)) {
            $query->where('employee_id', (int) $this->carryForwardEmployeeId);
        }

        if ($this->carryForwardLeaveTypeId !== '' && ctype_digit($this->carryForwardLeaveTypeId)) {
            $query->where('leave_type_id', (int) $this->carryForwardLeaveTypeId);
        }

        $pairs = $query->get();
        $preview = [];

        foreach ($pairs as $pair) {
            $policy = LeaveEntitlementPolicy::query()
                ->where('company_id', $companyId)
                ->where('leave_type_id', $pair->leave_type_id)
                ->orderByDesc('effective_from')
                ->first();

            if ($policy === null) {
                continue;
            }

            $outcome = $service->compute(
                companyId: $companyId,
                employeeId: (int) $pair->employee_id,
                leaveTypeId: (int) $pair->leave_type_id,
                fromYear: $this->carryForwardFromYear,
                policy: $policy,
                dryRun: true,
            );

            $preview[] = [
                'employee_id' => $outcome->employeeId,
                'leave_type_id' => $outcome->leaveTypeId,
                'remaining' => $outcome->remainingBalance,
                'cap' => $outcome->capDays,
                'carried' => $outcome->carriedForward,
                'expired' => $outcome->expiredAtYearEnd,
                'to_year' => $outcome->toYear,
                'policy_code' => $policy->code,
            ];
        }

        $this->carryForwardPreview = $preview;

        if ($preview === []) {
            session()->flash('error', __('No ledger entries found for the chosen filters.'));
        }
    }

    public function commitCarryForward(): void
    {
        $this->authorizeManage();

        if ($this->carryForwardPreview === []) {
            session()->flash('error', __('Generate a preview first.'));

            return;
        }

        $companyId = $this->companyId();
        $service = app(CarryForwardService::class);
        $count = 0;

        foreach ($this->carryForwardPreview as $row) {
            $policy = LeaveEntitlementPolicy::query()
                ->where('company_id', $companyId)
                ->where('leave_type_id', (int) $row['leave_type_id'])
                ->orderByDesc('effective_from')
                ->first();

            if ($policy === null) {
                continue;
            }

            $service->compute(
                companyId: $companyId,
                employeeId: (int) $row['employee_id'],
                leaveTypeId: (int) $row['leave_type_id'],
                fromYear: $this->carryForwardFromYear,
                policy: $policy,
                dryRun: false,
            );
            $count++;
        }

        $this->carryForwardPreview = [];
        session()->flash('success', __('Carry-forward committed for :n (employee, leave-type) pair(s).', ['n' => $count]));
    }

    /** @return list<array{occurs_on: string, name: string, scope: string, state_codes: list<string>, substituted: bool}> */
    private function resolveHolidays(): array
    {
        $registry = app(LeaveCountryPackRegistry::class);

        if (! $registry->hasCountry('MY')) {
            return [];
        }

        $calendar = $registry->forCountry('MY')->publicHolidayCalendar();
        $holidays = $calendar->publicHolidaysForYear($this->calendarYear, $this->calendarState ?: null);

        return array_map(static fn ($h) => [
            'occurs_on' => $h->occursOn->format('Y-m-d'),
            'name' => $h->name,
            'scope' => $h->scope,
            'state_codes' => $h->stateCodes,
            'substituted' => $h->substitutedFrom !== null,
        ], $holidays);
    }

    private function blankToNull(mixed $value): mixed
    {
        if ($value === null || $value === '') {
            return null;
        }

        return $value;
    }
}
