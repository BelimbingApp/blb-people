<?php

namespace App\Modules\People\Leave\Console\Commands;

use App\Modules\Core\Employee\Models\Employee;
use App\Modules\People\Leave\Models\LeaveAssignment;
use App\Modules\People\Leave\Services\CarryForwardService;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * Year-end carry-forward sweep: for every (employee, leave_type) covered by
 * an active assignment whose entitlement policy declares a bring-forward cap,
 * compute the carry-forward + expiry outcome and (unless --dry-run) write
 * the matching ledger entries.
 */
#[AsCommand(name: 'blb:leave:carry-forward')]
class CarryForwardCommand extends Command
{
    protected $description = 'Run year-end leave carry-forward across all employees with active assignments';

    protected $signature = 'blb:leave:carry-forward
                            {--from-year= : Year to carry forward FROM (defaults to last calendar year)}
                            {--company= : Limit to a single company ID}
                            {--leave-type= : Limit to a leave type code}
                            {--employee= : Limit to a single employee ID}
                            {--dry-run : Compute and report without writing ledger entries}';

    public function handle(CarryForwardService $service): int
    {
        $fromYear = (int) ($this->option('from-year') ?? (now()->year - 1));
        $dryRun = (bool) $this->option('dry-run');

        $query = LeaveAssignment::query()
            ->with(['leaveType', 'entitlementPolicy'])
            ->where('status', 'active');

        if ($company = $this->option('company')) {
            $query->where('company_id', (int) $company);
        }

        $employeeFilter = $this->option('employee') !== null ? (int) $this->option('employee') : null;
        $leaveTypeCode = $this->option('leave-type');

        $assignments = $query->get();
        $reported = 0;
        $carriedTotal = 0.0;
        $expiredTotal = 0.0;

        foreach ($assignments as $assignment) {
            if ($leaveTypeCode !== null && $assignment->leaveType?->code !== $leaveTypeCode) {
                continue;
            }
            if ($assignment->entitlementPolicy?->bring_forward_cap_days === null) {
                continue;
            }

            $employees = $employeeFilter !== null
                ? Employee::query()->where('id', $employeeFilter)->get()
                : $this->employeesForAssignment($assignment);

            foreach ($employees as $employee) {
                $outcome = $service->compute(
                    companyId: $assignment->company_id,
                    employeeId: $employee->getKey(),
                    leaveTypeId: $assignment->leave_type_id,
                    fromYear: $fromYear,
                    policy: $assignment->entitlementPolicy,
                    dryRun: $dryRun,
                );

                $this->line(sprintf(
                    '%s emp=%d type=%s remaining=%.2f cap=%.2f → carried=%.2f expired=%.2f',
                    $dryRun ? '[dry-run]' : '[applied]',
                    $employee->getKey(),
                    $assignment->leaveType?->code ?? '?',
                    $outcome->remainingBalance,
                    $outcome->capDays,
                    $outcome->carriedForward,
                    $outcome->expiredAtYearEnd,
                ));

                $reported++;
                $carriedTotal += $outcome->carriedForward;
                $expiredTotal += $outcome->expiredAtYearEnd;
            }
        }

        $this->info(sprintf(
            'Processed %d employee×type rows. Carried=%.2f Expired=%.2f.',
            $reported,
            $carriedTotal,
            $expiredTotal,
        ));

        return self::SUCCESS;
    }

    /** @return Collection<int, Employee> */
    private function employeesForAssignment(LeaveAssignment $assignment)
    {
        return Employee::query()->where('company_id', $assignment->company_id)->get();
    }
}
