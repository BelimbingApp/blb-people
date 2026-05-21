<?php

namespace App\Modules\People\Attendance\Livewire;

use App\Modules\Core\Employee\Models\Employee;
use App\Modules\Core\User\Models\User;
use App\Modules\People\Attendance\Livewire\Concerns\InteractsWithAttendanceScreen;
use App\Modules\People\Attendance\Models\AttendanceRosterCellLog;
use Illuminate\Contracts\View\View;
use Illuminate\Pagination\LengthAwarePaginator;
use Livewire\Component;
use Livewire\WithPagination;

class RosterEmployeeHistory extends Component
{
    use InteractsWithAttendanceScreen;
    use WithPagination;

    public string $employeeId = '';

    public string $fromDate = '';

    public string $toDate = '';

    public function mount(string $employeeId = ''): void
    {
        $this->employeeId = $employeeId !== '' ? $employeeId : (string) request()->query('employee_id', '');
        $this->toDate = now()->toDateString();
        $this->fromDate = now()->subDays(89)->toDateString();
    }

    public function render(): View
    {
        $companyId = $this->companyId();

        $employee = $this->employeeId !== ''
            ? Employee::query()->where('company_id', $companyId)->find((int) $this->employeeId)
            : null;

        $rows = collect();

        if ($employee instanceof Employee) {
            $query = AttendanceRosterCellLog::query()
                ->where('company_id', $companyId)
                ->where('employee_id', $employee->id)
                ->with(['previousShift', 'newShift', 'previousPolicy', 'newPolicy'])
                ->orderByDesc('changed_at')
                ->orderByDesc('id');

            if ($this->fromDate !== '') {
                $query->whereDate('date', '>=', $this->fromDate);
            }

            if ($this->toDate !== '') {
                $query->whereDate('date', '<=', $this->toDate);
            }

            $rows = $query->paginate(50);
        }

        // Resolve changed_by names in batch
        $userIds = $rows instanceof LengthAwarePaginator
            ? $rows->getCollection()->pluck('changed_by')->filter()->unique()->all()
            : [];
        $userNames = User::query()->whereKey($userIds)->pluck('name', 'id');

        return view('livewire.people.attendance.roster-employee-history', [
            'employee' => $employee,
            'rows' => $rows,
            'userNames' => $userNames,
        ]);
    }
}
