<?php

namespace App\Domains\People\Attendance\Livewire;

use App\Base\Audit\Models\AuditMutation;
use App\Base\Authz\Enums\PrincipalType;
use App\Base\Foundation\Livewire\Concerns\InteractsWithNotifications;
use App\Base\Tenancy\Contracts\TenantContext;
use App\Core\Employee\Models\Employee;
use App\Core\User\Models\User;
use App\Domains\People\Attendance\Livewire\Concerns\InteractsWithAttendanceScreen;
use App\Domains\People\Provider\Contracts\ResolvesWorkforceSubjects;
use App\Domains\People\Provider\Data\WorkforceSubject;
use App\Domains\People\Provider\Enums\WorkforceResourceType;
use Illuminate\Contracts\View\View;
use Illuminate\Pagination\LengthAwarePaginator;
use Livewire\Component;
use Livewire\WithPagination;

class RosterEmployeeHistory extends Component
{
    use InteractsWithAttendanceScreen;
    use InteractsWithNotifications;
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
        $employee = null;
        $refused = false;

        // The id came from the request. It is a workforce subject, so it is
        // resolved through the seam with the acting user's company (plan 0001,
        // #249): a subject of another company, a deactivated one, or an id
        // that is not a stable id is refused, never coerced into a lookup.
        if ($this->employeeId !== '') {
            $resolution = app(ResolvesWorkforceSubjects::class)->resolve(new WorkforceSubject(
                app(TenantContext::class)->requireTenantId(),
                $companyId,
                WorkforceResourceType::Employee,
                $this->employeeId,
            ));

            $employee = $resolution->record instanceof Employee ? $resolution->record : null;
            $refused = $employee === null;
        }

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
            'refused' => $refused,
            'rows' => $rows,
            'userNames' => $userNames,
        ]);
    }
}
