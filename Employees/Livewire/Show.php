<?php

namespace App\Modules\People\Employees\Livewire;

use App\Base\Authz\Contracts\AuthorizationService;
use App\Base\Authz\DTO\Actor;
use App\Base\Foundation\Livewire\Concerns\InteractsWithNotifications;
use App\Modules\Core\Company\Models\Company;
use App\Modules\Core\Employee\Models\Employee;
use App\Modules\Core\User\Models\User;
use App\Modules\People\Employees\Services\EmployeePayrollReadinessService;
use App\Modules\People\Employees\Services\EmployeeProfileChangeRequestReviewService;
use App\Modules\People\Settings\Models\EmployeeProfileChangeRequest;
use App\Modules\People\Settings\Models\EmployeeWorkProfile;
use App\Modules\People\Settings\Models\PeopleNotificationDeliveryLog;
use App\Modules\People\Settings\Models\PeopleReferenceEntry;
use App\Modules\People\Settings\Services\EmployeePortalAccessService;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;
use Livewire\Component;

class Show extends Component
{
    use InteractsWithNotifications;

    public Employee $employee;

    public string $fullName = '';

    public string $shortName = '';

    public string $employeeNumber = '';

    public string $designation = '';

    public string $email = '';

    public string $mobileNumber = '';

    public string $status = 'active';

    public ?int $costCenterId = null;

    public ?int $organizationUnitId = null;

    public ?int $employmentGroupId = null;

    public ?int $jobTitleId = null;

    public ?int $workforceClassId = null;

    public ?int $jobGradeId = null;

    public ?int $workCalendarId = null;

    public string $payRateType = '';

    public ?string $hiredOn = null;

    public ?string $resignedOn = null;

    public string $accessLoginIdentifier = '';

    public string $accessEmail = '';

    /**
     * @var array<int, string>
     */
    public array $requestReviewNotes = [];

    public function mount(Employee $employee): void
    {
        abort_unless(in_array($employee->company_id, $this->companyTreeIds(), true), 404);

        $this->employee = $employee;
        $this->refreshEmployee();
        $this->fillForms();
    }

    public function saveEmployeeDetails(): void
    {
        $this->authorizeCapability('people.employee.manage');

        $validated = $this->validate([
            'fullName' => ['required', 'string', 'max:255'],
            'shortName' => ['nullable', 'string', 'max:255'],
            'employeeNumber' => [
                'required',
                'string',
                'max:255',
                Rule::unique('employees', 'employee_number')
                    ->where(fn ($query) => $query->where('company_id', $this->employee->company_id))
                    ->ignore($this->employee->id),
            ],
            'designation' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'mobileNumber' => ['nullable', 'string', 'max:255'],
            'status' => ['required', 'in:pending,probation,active,inactive,terminated'],
        ]);

        $this->employee->fill([
            'full_name' => $validated['fullName'],
            'short_name' => $validated['shortName'] ?: null,
            'employee_number' => $validated['employeeNumber'],
            'designation' => $validated['designation'] ?: null,
            'email' => $validated['email'] ?: null,
            'mobile_number' => $validated['mobileNumber'] ?: null,
            'status' => $validated['status'],
        ])->save();

        $this->refreshEmployee();
        $this->notify(__('Employee details saved.'));
    }

    public function saveWorkProfile(): void
    {
        $this->authorizeCapability('people.employee.manage');

        $validated = $this->validate([
            'costCenterId' => ['nullable', $this->referenceRule(PeopleReferenceEntry::TYPE_COST_CENTER)],
            'organizationUnitId' => ['nullable', $this->referenceRule(PeopleReferenceEntry::TYPE_ORGANIZATION_UNIT)],
            'employmentGroupId' => ['nullable', $this->referenceRule(PeopleReferenceEntry::TYPE_EMPLOYMENT_GROUP)],
            'jobTitleId' => ['nullable', $this->referenceRule(PeopleReferenceEntry::TYPE_JOB_TITLE)],
            'workforceClassId' => ['nullable', $this->referenceRule(PeopleReferenceEntry::TYPE_WORKFORCE_CLASS)],
            'jobGradeId' => ['nullable', $this->referenceRule(PeopleReferenceEntry::TYPE_JOB_GRADE)],
            'workCalendarId' => ['nullable', $this->referenceRule(PeopleReferenceEntry::TYPE_WORK_CALENDAR)],
            'payRateType' => ['nullable', 'string', 'max:120'],
            'hiredOn' => ['nullable', 'date'],
            'resignedOn' => ['nullable', 'date', 'after_or_equal:hiredOn'],
        ]);

        EmployeeWorkProfile::query()->updateOrCreate(
            ['employee_id' => $this->employee->id],
            [
                'cost_center_id' => $validated['costCenterId'],
                'organization_unit_id' => $validated['organizationUnitId'],
                'employment_group_id' => $validated['employmentGroupId'],
                'job_title_id' => $validated['jobTitleId'],
                'workforce_class_id' => $validated['workforceClassId'],
                'job_grade_id' => $validated['jobGradeId'],
                'work_calendar_id' => $validated['workCalendarId'],
                'pay_rate_type' => $validated['payRateType'] !== '' ? $validated['payRateType'] : null,
                'hired_on' => $validated['hiredOn'],
                'resigned_on' => $validated['resignedOn'],
            ],
        );

        $this->refreshEmployee();
        $this->fillForms();
        $this->notify(__('Work profile saved.'));
    }

    public function provisionAccess(EmployeePortalAccessService $portalAccesses): void
    {
        $this->authorizeCapability('people.employee.manage');

        $portalAccesses->provision(
            employee: $this->employee->fresh(['user']),
            user: $this->employee->user,
            loginIdentifier: $this->accessLoginIdentifier !== '' ? $this->accessLoginIdentifier : null,
            email: $this->accessEmail !== '' ? $this->accessEmail : null,
        );

        $this->refreshEmployee();
        $this->fillForms();
        $this->notify(__('Employee account access provisioned.'));
    }

    public function sendAccessInvitation(EmployeePortalAccessService $portalAccesses): void
    {
        $this->authorizeCapability('people.employee.manage');

        if ($this->employee->portalAccess === null) {
            $this->provisionAccess($portalAccesses);
        }

        $portalAccesses->sendAccessInvitation($this->employee->portalAccess->fresh(['employee']), $this->employee->company_id);

        $this->refreshEmployee();
        $this->notify(__('Access invitation queued.'));
    }

    public function activateAccess(): void
    {
        $this->authorizeCapability('people.employee.manage');
        abort_if($this->employee->portalAccess === null, 404);

        $this->employee->portalAccess->activate();
        $this->refreshEmployee();
        $this->notify(__('Employee account access activated.'));
    }

    public function revokeAccess(): void
    {
        $this->authorizeCapability('people.employee.manage');
        abort_if($this->employee->portalAccess === null, 404);

        $this->employee->portalAccess->revoke();
        $this->refreshEmployee();
        $this->notify(__('Employee account access revoked.'));
    }

    public function approveRequest(int $requestId, EmployeeProfileChangeRequestReviewService $reviews): void
    {
        $this->authorizeCapability('people.employee.review');

        $request = $this->requestForReview($requestId);
        $reviewer = Auth::user();
        abort_unless($reviewer instanceof User, 403);

        $reviews->approve($request, $reviewer, $this->requestReviewNotes[$requestId] ?? null);

        $this->refreshEmployee();
        $this->notify(__('Profile change request approved.'));
    }

    public function rejectRequest(int $requestId, EmployeeProfileChangeRequestReviewService $reviews): void
    {
        $this->authorizeCapability('people.employee.review');

        $request = $this->requestForReview($requestId);
        $reviewer = Auth::user();
        abort_unless($reviewer instanceof User, 403);

        $reviews->reject($request, $reviewer, $this->requestReviewNotes[$requestId] ?? null);

        $this->refreshEmployee();
        $this->notify(__('Profile change request rejected.'));
    }

    public function statusVariant(string $status): string
    {
        return match ($status) {
            'active', 'approved' => 'success',
            'pending', 'probation', 'submitted' => 'warning',
            'rejected', 'terminated', 'revoked' => 'danger',
            default => 'default',
        };
    }

    public function requestChangeGroups(array $changes): array
    {
        $groups = [];

        foreach ($changes as $key => $value) {
            if ($key === 'employee' && is_array($value)) {
                $groups[] = ['label' => 'Employee', 'changes' => $value];
            } elseif ($key === 'work_profile' && is_array($value)) {
                $groups[] = ['label' => 'Work Profile', 'changes' => $value];
            }
        }

        if ($groups !== []) {
            return $groups;
        }

        return [['label' => 'Employee', 'changes' => $changes]];
    }

    public function render(EmployeePayrollReadinessService $readiness): View
    {
        return view('people-employees::livewire.people.employees.show', [
            'readiness' => $readiness->summarize($this->employee),
            'costCenters' => $this->referenceOptions(PeopleReferenceEntry::TYPE_COST_CENTER, $this->employee->workProfile?->cost_center_id),
            'organizationUnits' => $this->referenceOptions(PeopleReferenceEntry::TYPE_ORGANIZATION_UNIT, $this->employee->workProfile?->organization_unit_id),
            'employmentGroups' => $this->referenceOptions(PeopleReferenceEntry::TYPE_EMPLOYMENT_GROUP, $this->employee->workProfile?->employment_group_id),
            'jobTitles' => $this->referenceOptions(PeopleReferenceEntry::TYPE_JOB_TITLE, $this->employee->workProfile?->job_title_id),
            'workforceClasses' => $this->referenceOptions(PeopleReferenceEntry::TYPE_WORKFORCE_CLASS, $this->employee->workProfile?->workforce_class_id),
            'jobGrades' => $this->referenceOptions(PeopleReferenceEntry::TYPE_JOB_GRADE, $this->employee->workProfile?->job_grade_id),
            'workCalendars' => $this->referenceOptions(PeopleReferenceEntry::TYPE_WORK_CALENDAR, $this->employee->workProfile?->work_calendar_id),
            'notificationLogs' => PeopleNotificationDeliveryLog::query()
                ->where('company_id', $this->employee->company_id)
                ->where('notifiable_type', $this->employee->portalAccess?->getMorphClass())
                ->where('notifiable_id', $this->employee->portalAccess?->id)
                ->latest('id')
                ->limit(10)
                ->get(),
        ]);
    }

    private function fillForms(): void
    {
        $workProfile = $this->employee->workProfile;
        $access = $this->employee->portalAccess;

        $this->fullName = $this->employee->full_name;
        $this->shortName = $this->employee->short_name ?? '';
        $this->employeeNumber = $this->employee->employee_number;
        $this->designation = $this->employee->designation ?? '';
        $this->email = $this->employee->email ?? '';
        $this->mobileNumber = $this->employee->mobile_number ?? '';
        $this->status = $this->employee->status;

        $this->costCenterId = $workProfile?->cost_center_id;
        $this->organizationUnitId = $workProfile?->organization_unit_id;
        $this->employmentGroupId = $workProfile?->employment_group_id;
        $this->jobTitleId = $workProfile?->job_title_id;
        $this->workforceClassId = $workProfile?->workforce_class_id;
        $this->jobGradeId = $workProfile?->job_grade_id;
        $this->workCalendarId = $workProfile?->work_calendar_id;
        $this->payRateType = $workProfile?->pay_rate_type ?? '';
        $this->hiredOn = $workProfile?->hired_on?->toDateString();
        $this->resignedOn = $workProfile?->resigned_on?->toDateString();

        $this->accessLoginIdentifier = $access?->login_identifier ?? ($this->employee->user?->email ?? $this->employee->employee_number);
        $this->accessEmail = $access?->email ?? ($this->employee->user?->email ?? $this->employee->email ?? '');
    }

    private function refreshEmployee(): void
    {
        $this->employee->refresh();
        $this->employee->load([
            'company',
            'department.type',
            'supervisor',
            'user',
            'subordinates.department.type',
            'addresses',
            'workProfile.costCenter',
            'workProfile.organizationUnit',
            'workProfile.employmentGroup',
            'workProfile.jobTitle',
            'workProfile.workforceClass',
            'workProfile.jobGrade',
            'workProfile.workCalendar',
            'portalAccess.user',
            'profileChangeRequests' => fn ($query) => $query->latest('submitted_at')->latest('id'),
            'profileChangeRequests.requestedBy',
            'profileChangeRequests.reviewedBy',
        ]);
    }

    private function requestForReview(int $requestId): EmployeeProfileChangeRequest
    {
        return $this->employee->profileChangeRequests()
            ->whereKey($requestId)
            ->firstOrFail();
    }

    private function referenceRule(string $type): Exists
    {
        return Rule::exists('people_reference_entries', 'id')->where(
            fn ($query) => $query
                ->where('company_id', $this->employee->company_id)
                ->where('type', $type),
        );
    }

    /**
     * @return Collection<int, PeopleReferenceEntry>
     */
    private function referenceOptions(string $type, ?int $selectedId): Collection
    {
        return PeopleReferenceEntry::query()
            ->where('company_id', $this->employee->company_id)
            ->where('type', $type)
            ->where(function ($query) use ($selectedId): void {
                $query->where('status', PeopleReferenceEntry::STATUS_ACTIVE);

                if ($selectedId !== null) {
                    $query->orWhere('id', $selectedId);
                }
            })
            ->orderBy('status')
            ->orderBy('name')
            ->get();
    }

    private function authorizeCapability(string $capability): void
    {
        app(AuthorizationService::class)->authorize(
            Actor::forUser(Auth::user()),
            $capability,
        );
    }

    /**
     * @return list<int>
     */
    private function companyTreeIds(): array
    {
        $rootCompanyId = (int) (Auth::user()?->company_id ?? Company::LICENSEE_ID);
        $ids = [];
        $queue = [$rootCompanyId];

        while ($queue !== []) {
            $batch = $queue;
            $queue = [];
            array_push($ids, ...$batch);

            $children = Company::query()
                ->whereIn('parent_id', $batch)
                ->pluck('id')
                ->all();

            array_push($queue, ...$children);
        }

        return array_values(array_unique($ids));
    }
}
