<?php

namespace App\Modules\People\Attendance\Livewire;

use App\Base\Audit\Models\AuditMutation;
use App\Base\Authz\Enums\PrincipalType;
use App\Modules\Core\Employee\Models\Employee;
use App\Modules\Core\User\Models\User;
use App\Modules\People\Attendance\Livewire\Concerns\InteractsWithAttendanceScreen;
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
            $query = AuditMutation::query()
                ->where('company_id', $companyId)
                ->where('subject_name', 'employee')
                ->where('subject_id', (string) $employee->id)
                ->where('source', 'expanded')
                ->orderByDesc('occurred_at')
                ->orderByDesc('id');

            if ($this->fromDate !== '') {
                $query->where('subject_identifier', '>=', $this->fromDate);
            }

            if ($this->toDate !== '') {
                $query->where('subject_identifier', '<=', $this->toDate);
            }

            $rows = $query->paginate(50);
        }

        // Resolve changed_by names in batch
        $userIds = $rows instanceof LengthAwarePaginator
            ? $rows->getCollection()->where('actor_type', PrincipalType::USER->value)->pluck('actor_id')->filter()->unique()->all()
            : [];
        $userNames = User::query()->whereKey($userIds)->pluck('name', 'id');

        return view('people-attendance::livewire.people.attendance.roster-employee-history', [
            'employee' => $employee,
            'rows' => $rows,
            'userNames' => $userNames,
        ]);
    }
}
