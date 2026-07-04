<?php

use App\Modules\Core\Company\Models\Company;
use App\Modules\Core\Employee\Models\Employee;
use App\Modules\People\Leave\Models\LeaveRequest;
use App\Modules\People\Leave\Models\LeaveType;
use Livewire\Livewire;

function createLeaveWidgetFixture(int $companyId): array
{
    $employee = Employee::factory()->create(['company_id' => $companyId]);

    $type = LeaveType::query()->create([
        'company_id' => $companyId,
        'code' => 'AL_WIDGET',
        'name' => 'Annual Leave',
        'paid' => true,
        'default_unit' => LeaveType::UNIT_DAY,
        'default_approval_depth' => 1,
        'interacts_with_payroll' => false,
        'compulsory_attachment' => false,
        'status' => LeaveType::STATUS_ACTIVE,
    ]);

    return [$employee, $type];
}

function createLeaveWidgetRequest(int $companyId, int $employeeId, int $typeId, string $status, string $startsOn): LeaveRequest
{
    return LeaveRequest::query()->create([
        'company_id' => $companyId,
        'employee_id' => $employeeId,
        'leave_type_id' => $typeId,
        'status' => $status,
        'starts_on' => $startsOn,
        'ends_on' => $startsOn,
        'unit' => LeaveRequest::UNIT_DAY,
        'quantity' => 1,
    ]);
}

it('counts only submitted requests in the viewer company', function (): void {
    $admin = createAdminUser();
    [$employee, $type] = createLeaveWidgetFixture($admin->company_id);

    // Included: submitted in the admin's company.
    createLeaveWidgetRequest($admin->company_id, $employee->id, $type->id, LeaveRequest::STATUS_SUBMITTED, '2026-08-03');

    // Excluded: already approved.
    createLeaveWidgetRequest($admin->company_id, $employee->id, $type->id, LeaveRequest::STATUS_APPROVED, '2026-08-10');

    // Excluded: submitted, but in another company.
    $otherCompany = Company::factory()->create();
    [$otherEmployee, $otherType] = createLeaveWidgetFixture($otherCompany->id);
    createLeaveWidgetRequest($otherCompany->id, $otherEmployee->id, $otherType->id, LeaveRequest::STATUS_SUBMITTED, '2026-08-01');

    Livewire::actingAs($admin)
        ->test('people.leave.widgets.pending-approvals')
        ->assertViewHas('pendingCount', 1);
});

it('renders an honest empty state when nothing is waiting', function (): void {
    $admin = createAdminUser();

    Livewire::actingAs($admin)
        ->test('people.leave.widgets.pending-approvals')
        ->assertViewHas('pendingCount', 0)
        ->assertSee(__('No leave requests waiting for review.'));
});
