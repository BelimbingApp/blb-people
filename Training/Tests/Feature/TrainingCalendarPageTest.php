<?php

use App\Base\Authz\Enums\PrincipalType;
use App\Base\Authz\Models\PrincipalRole;
use App\Base\Authz\Models\Role;
use App\Base\Tenancy\Contracts\TenantContext;
use App\Core\Company\Models\Company;
use App\Core\Company\Models\Department;
use App\Core\Company\Models\DepartmentType;
use App\Core\Employee\Models\Employee;
use App\Core\User\Models\User;
use App\Domains\People\Settings\Models\EmployeePortalAccess;
use App\Domains\People\Settings\Models\EmployeeWorkProfile;
use App\Domains\People\Settings\Models\PeopleReferenceEntry;
use App\Domains\People\Skills\Data\SkillDraft;
use App\Domains\People\Skills\Models\SkillActorBinding;
use App\Domains\People\Skills\Services\SkillAudienceAssignmentStore;
use App\Domains\People\Skills\Services\SkillCatalogStore;
use App\Domains\People\Training\Data\TrainingCourseDraft;
use App\Domains\People\Training\Data\TrainingEventDraft;
use App\Domains\People\Training\Enums\DeliveryMode;
use App\Domains\People\Training\Livewire\Calendar\Index as TrainingCalendar;
use App\Domains\People\Training\Models\TrainingEvent;
use App\Domains\People\Training\Models\TrainingParticipant;
use App\Domains\People\Training\Services\TrainingCatalogStore;
use App\Domains\People\Training\Services\TrainingEventStore;
use Illuminate\Support\Str;
use Livewire\Livewire;

function calendarParticipantCount(int $tenantId, int $companyId, TrainingEvent $event): int
{
    return TrainingParticipant::query()->forCompany($tenantId, $companyId)->where('event_id', $event->id)->whereNull('withdrawn_at')->count();
}

/**
 * Training calendar page (0005-g): month view of scheduled events with
 * participant enrolment for authorized users.
 */
afterEach(function (): void {
    app(TenantContext::class)->clear();
});

beforeEach(function (): void {
    $this->withoutVite();
});

function calendarUser(Company $company, string ...$roleCodes): User
{
    $user = User::factory()->create(['company_id' => $company->id]);
    foreach ($roleCodes as $roleCode) {
        PrincipalRole::query()->create([
            'company_id' => $company->id, 'principal_type' => PrincipalType::USER->value,
            'principal_id' => $user->id,
            'role_id' => Role::query()->whereNull('company_id')->where('code', $roleCode)->sole()->id,
        ]);
    }

    return $user;
}

function calendarEmployee(User $user, Company $company, Employee $employee, int $tenantId): void
{
    EmployeePortalAccess::query()->create([
        'employee_id' => $employee->id, 'user_id' => $user->id,
        'display_name' => $employee->full_name, 'status' => EmployeePortalAccess::STATUS_ACTIVE,
    ]);
    SkillActorBinding::query()->create([
        'tenant_id' => $tenantId, 'company_entity_id' => $company->id,
        'platform_user_id' => $user->id, 'employee_entity_id' => $employee->id,
        'user_entity_id' => $user->id, 'confirmed_by_user_id' => $user->id,
        'review_reference' => 'training-calendar-fixture', 'confirmed_at' => now(),
    ]);
}

/** @return array{tenantId: int, company: Company, hr: User, hod: User, employee: User, trainer: User, headEntryId: int} */
function calendarFixture(): array
{
    [$tenant, $company] = createTenantWithCompany(['name' => 'Training calendar company']);
    $tenantId = (int) $tenant->id;
    app(TenantContext::class)->set($tenantId);
    setupAuthzRoles();

    $hr = calendarUser($company, 'people_hr');
    $hod = calendarUser($company, 'people_hod');
    $employeeUser = calendarUser($company, 'people_employee');
    $trainerUser = calendarUser($company, 'people_employee', 'people_training_trainer');

    $entry = PeopleReferenceEntry::query()->create([
        'company_id' => $company->id, 'type' => PeopleReferenceEntry::TYPE_ORGANIZATION_UNIT,
        'code' => 'CAL-OPS', 'name' => 'Calendar operations', 'status' => PeopleReferenceEntry::STATUS_ACTIVE,
    ]);
    $type = DepartmentType::query()->firstOrCreate(['code' => 'cal-ops'], ['name' => 'Calendar operations', 'category' => 'operational', 'is_active' => true]);
    $department = Department::query()->create(['company_id' => $company->id, 'department_type_id' => $type->id, 'status' => 'active']);
    $head = Employee::factory()->create(['company_id' => $company->id, 'department_id' => $department->id, 'full_name' => 'Calendar Head', 'status' => 'active']);
    $department->update(['head_id' => $head->id]);
    EmployeeWorkProfile::query()->create(['employee_id' => $head->id, 'organization_unit_id' => $entry->id]);
    $hod->update(['employee_id' => $head->id]);
    calendarEmployee($hod, $company, $head, $tenantId);
    app(SkillAudienceAssignmentStore::class)->confirmActor($hr, $hod, (int) $company->id, (int) $head->id, 'review:calendar-hod');

    $employee = Employee::factory()->create(['company_id' => $company->id, 'full_name' => 'Calendar Employee', 'status' => 'active']);
    $employeeUser->update(['employee_id' => $employee->id]);
    calendarEmployee($employeeUser, $company, $employee, $tenantId);

    $trainer = Employee::factory()->create(['company_id' => $company->id, 'full_name' => 'Calendar Trainer', 'status' => 'active']);
    $trainerUser->update(['employee_id' => $trainer->id]);
    calendarEmployee($trainerUser, $company, $trainer, $tenantId);

    $hrEmployee = Employee::factory()->create(['company_id' => $company->id, 'full_name' => 'Calendar HR', 'status' => 'active']);
    $hr->update(['employee_id' => $hrEmployee->id]);
    calendarEmployee($hr, $company, $hrEmployee, $tenantId);

    return [
        'tenantId' => $tenantId, 'company' => $company, 'hr' => $hr, 'hod' => $hod,
        'employee' => $employeeUser, 'trainer' => $trainerUser, 'headEntryId' => (int) $entry->id,
        'trainerEmployee' => $trainer,
    ];
}

function calendarEvent(Company $company, Employee $organizer, string $title, int $capacity = 10, ?int $departmentEntityId = null): TrainingEvent
{
    $catalog = app(SkillCatalogStore::class);
    $category = $catalog->defineCategory((int) $company->id, 'cal-'.Str::lower(Str::random(8)), 'Calendar');
    $skill = $catalog->defineSkill((int) $company->id, new SkillDraft(
        code: 'cal.'.Str::lower(Str::random(8)), name: $title.' skill', definition: 'A calendar test skill.', categoryId: (int) $category->id,
    ));
    $course = app(TrainingCatalogStore::class)->defineCourse((int) $company->id, new TrainingCourseDraft(
        code: 'cal.'.Str::lower(Str::random(8)).'.course', title: $title.' course', deliveryMode: DeliveryMode::InternalClassroom,
        skillIds: [(int) $skill->id], internalTrainerEmployeeEntityId: (int) $organizer->id,
    ));

    return app(TrainingEventStore::class)->schedule((int) $company->id, new TrainingEventDraft(
        courseId: (int) $course->id, startsAt: now()->addDays(3)->startOfHour(), endsAt: now()->addDays(3)->startOfHour()->addHours(2),
        capacity: $capacity, organizerEmployeeEntityId: (int) $organizer->id,
        targetDepartmentEntityId: $departmentEntityId,
    ));
}

it('shows scheduled events with capacity to HR on the calendar route', function (): void {
    $f = calendarFixture();
    $event = calendarEvent($f['company'], $f['trainerEmployee'], 'Company-wide briefing');

    expect(route('people.training.calendar', absolute: false))->toBe('/people/training-calendar');

    Livewire::actingAs($f['hr'])
        ->test(TrainingCalendar::class)
        ->assertSee('Company-wide briefing')
        ->assertSee('0 of 10 enrolled');
});

it('shows a company-wide event to an employee but hides another department event', function (): void {
    $f = calendarFixture();
    calendarEvent($f['company'], $f['trainerEmployee'], 'Open briefing');
    calendarEvent($f['company'], $f['trainerEmployee'], 'Ops deep dive', 10, $f['headEntryId']);

    Livewire::actingAs($f['employee'])
        ->test(TrainingCalendar::class)
        ->assertSee('Open briefing')
        ->assertDontSee('Ops deep dive');
});

it('shows a headed-department event to its HOD', function (): void {
    $f = calendarFixture();
    calendarEvent($f['company'], $f['trainerEmployee'], 'Ops deep dive', 10, $f['headEntryId']);

    Livewire::actingAs($f['hod'])
        ->test(TrainingCalendar::class)
        ->assertSee('Ops deep dive');
});

it('shows a trainer their own department-targeted event', function (): void {
    $f = calendarFixture();
    calendarEvent($f['company'], $f['trainerEmployee'], 'Trainer session', 10, $f['headEntryId']);

    Livewire::actingAs($f['trainer'])
        ->test(TrainingCalendar::class)
        ->assertSee('Trainer session');
});

it('refuses the calendar route without the calendar grant', function (): void {
    $f = calendarFixture();
    $outsider = User::factory()->create(['company_id' => $f['company']->id]);

    $this->actingAs($outsider)->get('/people/training-calendar')->assertForbidden();
    Livewire::actingAs($outsider)->test(TrainingCalendar::class)->assertForbidden();
});

it('enrols the signed-in employee and withdraws again', function (): void {
    $f = calendarFixture();
    $event = calendarEvent($f['company'], $f['trainerEmployee'], 'Open briefing');

    Livewire::actingAs($f['employee'])
        ->test(TrainingCalendar::class)
        ->call('enrol', $event->id)
        ->assertSee('1 of 10 enrolled');

    expect(calendarParticipantCount($f['tenantId'], (int) $f['company']->id, $event))->toBe(1);

    Livewire::actingAs($f['employee'])
        ->test(TrainingCalendar::class)
        ->call('withdraw', $event->id)
        ->assertSee('0 of 10 enrolled');

    expect(calendarParticipantCount($f['tenantId'], (int) $f['company']->id, $event))->toBe(0);

    // The withdrawn seat is freed and the same employee may enrol again.
    Livewire::actingAs($f['employee'])
        ->test(TrainingCalendar::class)
        ->call('enrol', $event->id)
        ->assertSee('1 of 10 enrolled');

    expect(calendarParticipantCount($f['tenantId'], (int) $f['company']->id, $event))->toBe(1);
});

it('refuses enrolment at capacity', function (): void {
    $f = calendarFixture();
    $event = calendarEvent($f['company'], $f['trainerEmployee'], 'Tiny briefing', 1);

    Livewire::actingAs($f['hr'])->test(TrainingCalendar::class)->call('enrol', $event->id);

    Livewire::actingAs($f['employee'])
        ->test(TrainingCalendar::class)
        ->call('enrol', $event->id)
        ->assertSee('is full');

    expect(calendarParticipantCount($f['tenantId'], (int) $f['company']->id, $event))->toBe(1);
});

it('refuses enrolment into an event outside the employee calendar', function (): void {
    $f = calendarFixture();
    $event = calendarEvent($f['company'], $f['trainerEmployee'], 'Ops deep dive', 10, $f['headEntryId']);

    Livewire::actingAs($f['employee'])
        ->test(TrainingCalendar::class)
        ->call('enrol', $event->id)
        ->assertSee('unavailable in the current scope');

    expect(calendarParticipantCount($f['tenantId'], (int) $f['company']->id, $event))->toBe(0);
});

it('hides sibling-company events from the calendar', function (): void {
    $f = calendarFixture();
    $other = Company::factory()->create(['tenant_id' => $f['tenantId'], 'name' => 'Sibling calendar co', 'status' => 'active']);
    $organizer = Employee::factory()->create(['company_id' => $other->id, 'full_name' => 'Sibling Organizer', 'status' => 'active']);
    calendarEvent($other, $organizer, 'Sibling briefing');

    Livewire::actingAs($f['hr'])
        ->test(TrainingCalendar::class)
        ->assertDontSee('Sibling briefing');
});
