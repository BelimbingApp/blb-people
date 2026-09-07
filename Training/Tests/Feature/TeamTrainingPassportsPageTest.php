<?php

use App\Base\Authz\Enums\PrincipalType;
use App\Base\Authz\Models\PrincipalRole;
use App\Base\Authz\Models\Role;
use App\Base\Tenancy\Contracts\TenantContext;
use App\Core\Company\Models\Department;
use App\Core\Company\Models\DepartmentType;
use App\Core\Employee\Models\Employee;
use App\Core\User\Models\User;
use App\Domains\People\Settings\Models\EmployeePortalAccess;
use App\Domains\People\Settings\Models\EmployeeWorkProfile;
use App\Domains\People\Settings\Models\PeopleReferenceEntry;
use App\Domains\People\Skills\Models\SkillActorBinding;
use App\Domains\People\Training\Enums\AttendanceStatus;
use App\Domains\People\Training\Enums\DeliveryMode;
use App\Domains\People\Training\Enums\TrainingEventStatus;
use App\Domains\People\Training\Livewire\TeamPassports;
use App\Domains\People\Training\Models\TrainingCourse;
use App\Domains\People\Training\Models\TrainingEvent;
use App\Domains\People\Training\Models\TrainingParticipant;
use App\Domains\People\Training\Models\TrainingParticipationFact;
use App\Domains\People\Training\Models\TrainingSession;
use Illuminate\Support\Str;
use Livewire\Livewire;

afterEach(function (): void {
    app(TenantContext::class)->clear();
});

beforeEach(function (): void {
    $this->withoutVite();
});

/** @return array{hod: User, member: Employee, outside: Employee, outsideUnit: PeopleReferenceEntry} */
function teamPassportsFixture(): array
{
    [$tenant, $company] = createTenantWithCompany(['name' => 'Team passport tenant']);
    app(TenantContext::class)->set((int) $tenant->id);

    $insideUnit = teamPassportsUnit((int) $company->id, 'Passport Operations', 'passport-ops');
    $outsideUnit = teamPassportsUnit((int) $company->id, 'Passport Finance', 'passport-finance');
    $insideType = DepartmentType::query()->create([
        'code' => 'passport-operations', 'name' => 'Passport operations',
        'category' => 'operational', 'is_active' => true,
    ]);
    $outsideType = DepartmentType::query()->create([
        'code' => 'passport-finance', 'name' => 'Passport finance',
        'category' => 'operational', 'is_active' => true,
    ]);
    $insideDepartment = Department::query()->create([
        'company_id' => $company->id, 'department_type_id' => $insideType->id, 'status' => 'active',
    ]);
    $outsideDepartment = Department::query()->create([
        'company_id' => $company->id, 'department_type_id' => $outsideType->id, 'status' => 'active',
    ]);

    $head = teamPassportsEmployee((int) $company->id, $insideDepartment, $insideUnit, 'Passport HOD');
    $insideDepartment->update(['head_id' => $head->id]);
    $member = teamPassportsEmployee((int) $company->id, $insideDepartment, $insideUnit, 'Passport Direct Report');
    $outside = teamPassportsEmployee((int) $company->id, $outsideDepartment, $outsideUnit, 'Passport Outside Department');
    $member->update(['supervisor_id' => $head->id]);
    // This reporting-line match makes the department filter load-bearing:
    // deleting it exposes this employee from another department.
    $outside->update(['supervisor_id' => $head->id]);

    $hod = User::factory()->create(['company_id' => $company->id, 'employee_id' => $head->id]);
    setupAuthzRoles();
    PrincipalRole::query()->create([
        'company_id' => $company->id, 'principal_type' => PrincipalType::USER->value,
        'principal_id' => $hod->id,
        'role_id' => Role::query()->whereNull('company_id')->where('code', 'people_hod')->sole()->id,
    ]);
    EmployeePortalAccess::query()->create([
        'employee_id' => $head->id, 'user_id' => $hod->id,
        'display_name' => $head->displayName(), 'status' => EmployeePortalAccess::STATUS_ACTIVE,
    ]);
    SkillActorBinding::query()->create([
        'tenant_id' => $tenant->id, 'company_entity_id' => $company->id,
        'platform_user_id' => $hod->id, 'employee_entity_id' => $head->id,
        'user_entity_id' => $hod->id, 'confirmed_by_user_id' => $hod->id,
        'review_reference' => 'team-passports-fixture', 'confirmed_at' => now(),
    ]);

    return compact('hod', 'member', 'outside', 'outsideUnit');
}

function teamPassportsUnit(int $companyId, string $name, string $code): PeopleReferenceEntry
{
    return PeopleReferenceEntry::query()->create([
        'company_id' => $companyId, 'type' => PeopleReferenceEntry::TYPE_ORGANIZATION_UNIT,
        'code' => $code, 'name' => $name, 'status' => PeopleReferenceEntry::STATUS_ACTIVE,
    ]);
}

function teamPassportsEmployee(
    int $companyId,
    Department $department,
    PeopleReferenceEntry $unit,
    string $name,
): Employee {
    $employee = Employee::factory()->create([
        'company_id' => $companyId, 'department_id' => $department->id,
        'full_name' => $name, 'short_name' => null, 'status' => 'active',
    ]);
    EmployeeWorkProfile::query()->create([
        'employee_id' => $employee->id, 'organization_unit_id' => $unit->id,
    ]);

    return $employee;
}

function teamPassportsTraining(User $actor, Employee $employee): void
{
    $tenantId = (int) $actor->tenant_id;
    $companyId = (int) $actor->company_id;
    $course = TrainingCourse::query()->create([
        'tenant_id' => $tenantId, 'company_entity_id' => $companyId,
        'code' => 'team-passport-'.Str::lower(Str::random(8)),
        'title' => 'Completed passport course', 'delivery_mode' => DeliveryMode::InternalClassroom,
        'internal_trainer_employee_entity_id' => $employee->id, 'active' => true,
    ]);
    $makeEvent = function (TrainingEventStatus $status, string $title, DateTimeImmutable $startsAt) use (
        $tenantId, $companyId, $course, $employee,
    ): TrainingEvent {
        return TrainingEvent::query()->create([
            'tenant_id' => $tenantId, 'company_entity_id' => $companyId,
            'event_key' => (string) Str::uuid(), 'course_id' => $course->id,
            'course_code_snapshot' => $course->code, 'course_title_snapshot' => $title,
            'delivery_mode_snapshot' => DeliveryMode::InternalClassroom,
            'organizer_employee_entity_id' => $employee->id,
            'internal_trainer_employee_entity_id' => $employee->id,
            'starts_at' => $startsAt, 'ends_at' => $startsAt->addHours(2),
            'capacity' => 10, 'status' => $status,
            'completed_at' => $status === TrainingEventStatus::Completed ? $startsAt->addHours(2) : null,
            'completion_evidence' => $status === TrainingEventStatus::Completed ? 'Completion register' : null,
        ]);
    };
    $scheduled = $makeEvent(TrainingEventStatus::Scheduled, 'Scheduled passport course', now()->addMonth()->toImmutable());
    $completed = $makeEvent(TrainingEventStatus::Completed, 'Completed passport course', now()->subMonth()->toImmutable());
    TrainingParticipant::query()->create([
        'tenant_id' => $tenantId, 'company_entity_id' => $companyId, 'event_id' => $scheduled->id,
        'provider_id' => 'core', 'employee_subject_id' => (string) $employee->id,
        'workforce_observed_at' => now(),
    ]);
    $participant = TrainingParticipant::query()->create([
        'tenant_id' => $tenantId, 'company_entity_id' => $companyId, 'event_id' => $completed->id,
        'provider_id' => 'core', 'employee_subject_id' => (string) $employee->id,
        'workforce_observed_at' => now(),
    ]);
    $session = TrainingSession::query()->create([
        'tenant_id' => $tenantId, 'company_entity_id' => $companyId, 'event_id' => $completed->id,
        'session_reference' => 'team-passport-session', 'starts_at' => $completed->starts_at,
        'ends_at' => $completed->ends_at, 'created_by_user_id' => $actor->id,
    ]);
    TrainingParticipationFact::query()->create([
        'tenant_id' => $tenantId, 'company_entity_id' => $companyId, 'event_id' => $completed->id,
        'participant_id' => $participant->id, 'session_id' => $session->id,
        'attendance' => AttendanceStatus::Present, 'actual_minutes' => 120,
        'certificate_reference' => 'CERT-TEAM-90', 'certificate_valid_from' => today(),
        'certificate_valid_until' => today()->addDays(30), 'evidence_references' => [],
        'source' => 'fixture', 'source_reference' => 'team-passport-fact',
        'recorded_by_user_id' => $actor->id, 'recorded_capability' => 'fixture', 'recorded_at' => now(),
    ]);
}

test('an hod sees only employees in the department they head', function (): void {
    $fixture = teamPassportsFixture();

    Livewire::actingAs($fixture['hod'])
        ->test(TeamPassports::class)
        ->assertSee('Passport Direct Report')
        ->assertDontSee('Passport Outside Department')
        ->assertDontSee('Passport HOD');
});

test('a request supplied department is ignored', function (): void {
    $fixture = teamPassportsFixture();

    $this->actingAs($fixture['hod'])
        ->get(route('people.training.team-passports', [
            'department_id' => $fixture['outsideUnit']->id,
        ]))
        ->assertOk()
        ->assertSee('Passport Direct Report')
        ->assertDontSee('Passport Outside Department');
});

test('the summary separates scheduled attended completed and expiring counts and drills into the passport', function (): void {
    $fixture = teamPassportsFixture();
    teamPassportsTraining($fixture['hod'], $fixture['member']);

    Livewire::actingAs($fixture['hod'])
        ->test(TeamPassports::class)
        ->assertSeeHtml('data-passport-counts="1:1:1:1"');

    $this->actingAs($fixture['hod'])
        ->get(route('people.training.team-passports', ['employeeId' => $fixture['member']->id]))
        ->assertOk()
        ->assertSee('Completed passport course')
        ->assertSee('CERT-TEAM-90');

    $this->actingAs($fixture['hod'])
        ->get(route('people.training.team-passports', ['employeeId' => $fixture['outside']->id]))
        ->assertNotFound();
});

test('the team passport capability is required', function (): void {
    $fixture = teamPassportsFixture();
    $stranger = User::factory()->create(['company_id' => $fixture['hod']->company_id]);

    $this->actingAs($stranger)
        ->get(route('people.training.team-passports'))
        ->assertForbidden();
});
