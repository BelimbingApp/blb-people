<?php

use App\Modules\Core\Company\Models\Company;
use App\Modules\Core\Employee\Models\Employee;
use App\Modules\People\Employees\Livewire\Index;
use App\Modules\People\Employees\Livewire\Show;
use App\Modules\People\Settings\Models\EmployeePortalAccess;
use App\Modules\People\Settings\Models\EmployeeProfileChangeRequest;
use App\Modules\People\Settings\Models\EmployeeWorkProfile;
use App\Modules\People\Settings\Models\PeopleNotificationDeliveryLog;
use App\Modules\People\Settings\Models\PeopleReferenceEntry;
use App\Modules\People\Settings\Models\PeopleSavedEmployeeView;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;

function createPeopleReference(Company $company, string $type, string $code, string $name): PeopleReferenceEntry
{
    return PeopleReferenceEntry::query()->create([
        'company_id' => $company->id,
        'type' => $type,
        'code' => $code,
        'name' => $name,
        'status' => PeopleReferenceEntry::STATUS_ACTIVE,
        'source_system' => 'ipayroll',
        'source_label' => ucfirst(str_replace('_', ' ', $type)),
        'source_code' => $code,
    ]);
}

function createEmployeeStatutoryProfileFixture(Company $company, Employee $employee, array $attributes = []): void
{
    if (! Schema::hasTable('people_payroll_employee_statutory_profiles')) {
        return;
    }

    $now = now();

    DB::table('people_payroll_employee_statutory_profiles')->insert([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'country_iso' => $attributes['country_iso'] ?? 'MY',
        'source_pack' => $attributes['source_pack'] ?? 'malaysia',
        'source_version' => $attributes['source_version'] ?? '2026.1',
        'effective_from' => $attributes['effective_from'] ?? '2026-01-01',
        'effective_to' => $attributes['effective_to'] ?? null,
        'profile_data' => json_encode($attributes['profile_data'] ?? [], JSON_THROW_ON_ERROR),
        'validation_messages' => json_encode($attributes['validation_messages'] ?? [], JSON_THROW_ON_ERROR),
        'metadata' => json_encode($attributes['metadata'] ?? [], JSON_THROW_ON_ERROR),
        'created_at' => $now,
        'updated_at' => $now,
    ]);
}

test('employee workbench renders readiness and saved view controls', function (): void {
    $user = createAdminUser();
    $company = Company::query()->findOrFail($user->company_id);

    $costCenter = createPeopleReference($company, PeopleReferenceEntry::TYPE_COST_CENTER, 'OPS', 'Operations');
    $organizationUnit = createPeopleReference($company, PeopleReferenceEntry::TYPE_ORGANIZATION_UNIT, 'HQ', 'Headquarters');
    $jobTitle = createPeopleReference($company, PeopleReferenceEntry::TYPE_JOB_TITLE, 'HRM', 'HR Manager');
    $workforceClass = createPeopleReference($company, PeopleReferenceEntry::TYPE_WORKFORCE_CLASS, 'MGT', 'Management');
    $jobGrade = createPeopleReference($company, PeopleReferenceEntry::TYPE_JOB_GRADE, 'G7', 'Grade 7');
    $workCalendar = createPeopleReference($company, PeopleReferenceEntry::TYPE_WORK_CALENDAR, 'MY-STD', 'Malaysia Standard');
    $employmentGroup = createPeopleReference($company, PeopleReferenceEntry::TYPE_EMPLOYMENT_GROUP, 'EXEC', 'Executive');

    $readyEmployee = Employee::factory()->create([
        'company_id' => $company->id,
        'employee_number' => 'E1001',
        'full_name' => 'Ada Ready',
        'status' => 'active',
        'metadata' => [
            'payroll_bank' => [
                'bank_name' => 'BLB Bank',
                'bank_account_number' => '123456789',
            ],
        ],
    ]);

    EmployeeWorkProfile::query()->create([
        'employee_id' => $readyEmployee->id,
        'cost_center_id' => $costCenter->id,
        'organization_unit_id' => $organizationUnit->id,
        'employment_group_id' => $employmentGroup->id,
        'job_title_id' => $jobTitle->id,
        'workforce_class_id' => $workforceClass->id,
        'job_grade_id' => $jobGrade->id,
        'work_calendar_id' => $workCalendar->id,
        'pay_rate_type' => 'monthly',
        'hired_on' => '2026-01-01',
    ]);

    EmployeePortalAccess::query()->create([
        'employee_id' => $readyEmployee->id,
        'login_identifier' => 'ada.ready',
        'display_name' => 'Ada Ready',
        'email' => 'ada.ready@example.test',
        'status' => EmployeePortalAccess::STATUS_ACTIVE,
    ]);

    createEmployeeStatutoryProfileFixture($company, $readyEmployee, [
        'profile_data' => ['epf' => '123'],
    ]);

    Employee::factory()->create([
        'company_id' => $company->id,
        'employee_number' => 'E1002',
        'full_name' => 'Ben Blocked',
        'status' => 'inactive',
    ]);

    $this->actingAs($user)
        ->get(route('people.employees.index'))
        ->assertOk()
        ->assertSee('Employee Workbench')
        ->assertSee('Saved Employee Views')
        ->assertSee('Ada Ready')
        ->assertSee('Ben Blocked')
        ->assertSee('Blocked');
});

test('employee workbench can save a shared view and export active filters', function (): void {
    $user = createAdminUser();
    $company = Company::query()->findOrFail($user->company_id);
    $employee = Employee::factory()->create([
        'company_id' => $company->id,
        'employee_number' => 'E2001',
        'full_name' => 'Cara Export',
        'status' => 'active',
        'metadata' => [
            'payroll_bank' => [
                'bank_name' => 'Review Bank',
                'bank_account_number' => '99887766',
            ],
        ],
    ]);
    $jobTitle = createPeopleReference($company, PeopleReferenceEntry::TYPE_JOB_TITLE, 'FIN', 'Finance Executive');

    EmployeeWorkProfile::query()->create([
        'employee_id' => $employee->id,
        'job_title_id' => $jobTitle->id,
        'pay_rate_type' => 'monthly',
        'hired_on' => '2026-01-01',
    ]);

    createEmployeeStatutoryProfileFixture($company, $employee, [
        'profile_data' => ['pcb' => 'A1'],
    ]);

    $this->actingAs($user);

    Livewire::test(Index::class)
        ->set('search', 'Cara')
        ->set('savedViewName', 'Payroll Ready')
        ->set('savedViewVisibility', 'company')
        ->call('saveCurrentView');

    expect(PeopleSavedEmployeeView::query()->where('company_id', $company->id)->where('name', 'Payroll Ready')->exists())->toBeTrue();

    $response = $this->get(route('people.employees.export.csv', ['search' => 'Cara']));
    ob_start();
    $response->sendContent();
    $content = (string) ob_get_clean();

    expect($response->getStatusCode())->toBe(200)
        ->and($content)->toContain('employee_number,employee_name')
        ->and($content)->toContain('E2001,"Cara Export"');
});

test('people employee detail updates work profile access and reviews requests', function (): void {
    $user = createAdminUser();
    $company = Company::query()->findOrFail($user->company_id);
    $employee = Employee::factory()->create([
        'company_id' => $company->id,
        'employee_number' => 'E3001',
        'full_name' => 'Dina Detail',
        'status' => 'active',
        'email' => 'dina@example.test',
    ]);

    $costCenter = createPeopleReference($company, PeopleReferenceEntry::TYPE_COST_CENTER, 'HR', 'Human Resources');
    $jobTitle = createPeopleReference($company, PeopleReferenceEntry::TYPE_JOB_TITLE, 'OFFICER', 'Officer');
    $request = EmployeeProfileChangeRequest::query()->create([
        'employee_id' => $employee->id,
        'requested_by_user_id' => $user->id,
        'request_type' => 'profile_update',
        'status' => EmployeeProfileChangeRequest::STATUS_SUBMITTED,
        'requested_changes' => [
            'mobile_number' => '+60111111111',
        ],
        'submitted_at' => now(),
    ]);

    $this->actingAs($user);

    Livewire::test(Show::class, ['employee' => $employee])
        ->set('costCenterId', $costCenter->id)
        ->set('jobTitleId', $jobTitle->id)
        ->set('payRateType', 'monthly')
        ->set('hiredOn', '2026-01-01')
        ->call('saveWorkProfile')
        ->set('accessLoginIdentifier', 'dina.detail')
        ->set('accessEmail', 'dina.detail@example.test')
        ->call('provisionAccess')
        ->call('sendAccessInvitation')
        ->call('activateAccess')
        ->set("requestReviewNotes.{$request->id}", 'Approved from employee workbench.')
        ->call('approveRequest', $request->id);

    expect($employee->fresh()->mobile_number)->toBe('+60111111111')
        ->and($employee->fresh()->workProfile?->cost_center_id)->toBe($costCenter->id)
        ->and($employee->fresh()->workProfile?->job_title_id)->toBe($jobTitle->id)
        ->and($employee->fresh()->workProfile?->pay_rate_type)->toBe('monthly')
        ->and($employee->fresh()->portalAccess?->status)->toBe(EmployeePortalAccess::STATUS_ACTIVE)
        ->and($employee->fresh()->portalAccess?->last_invited_at)->not->toBeNull()
        ->and(PeopleNotificationDeliveryLog::query()->where('notifiable_id', $employee->fresh()->portalAccess?->id)->exists())->toBeTrue()
        ->and($request->fresh()->status)->toBe(EmployeeProfileChangeRequest::STATUS_APPROVED);
});
