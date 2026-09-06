<?php

use App\Base\Authz\Enums\PrincipalType;
use App\Base\Authz\Models\PrincipalCapability;
use App\Base\Tenancy\Contracts\TenantContext;
use App\Core\Company\Models\Department;
use App\Core\Company\Models\DepartmentType;
use App\Core\Employee\Models\Employee;
use App\Core\User\Models\User;
use App\Domains\People\Organisation\Livewire\Explorer\Index;
use App\Domains\People\Provider\Enums\WorkforceResourceType;
use App\Domains\People\Settings\Models\EmployeePortalAccess;
use App\Domains\People\Settings\Models\EmployeeWorkProfile;
use App\Domains\People\Settings\Models\PeopleReferenceEntry;
use Illuminate\Support\Str;
use Livewire\Livewire;

afterEach(function (): void {
    app(TenantContext::class)->clear();
});

beforeEach(function (): void {
    $this->withoutVite();
});

function explorerGrant(User $user, string $capability): void
{
    PrincipalCapability::query()->create([
        'company_id' => $user->company_id,
        'principal_type' => PrincipalType::USER->value,
        'principal_id' => $user->id,
        'capability_key' => $capability,
        'is_allowed' => true,
    ]);
}

function explorerUser(int $companyId, ?Employee $employee = null): User
{
    $user = User::factory()->create([
        'company_id' => $companyId,
        'employee_id' => $employee?->getKey(),
    ]);

    if ($employee !== null) {
        EmployeePortalAccess::query()->create([
            'employee_id' => $employee->id,
            'user_id' => $user->id,
            'display_name' => $employee->displayName(),
            'status' => EmployeePortalAccess::STATUS_ACTIVE,
        ]);
    }

    return $user;
}

/**
 * Company with an Alpha unit (four employees, one vacant position) and a
 * Beta unit (one employee). The HOD heads Alpha's department; the employee
 * belongs to Alpha.
 *
 * @return array{tenant: mixed, company: mixed, unitA: PeopleReferenceEntry, unitB: PeopleReferenceEntry, hod: Employee, employee: Employee, beta: Employee}
 */
function explorerFixture(): array
{
    [$tenant, $company] = createTenantWithCompany(
        ['name' => 'Explorer tenant'],
        ['name' => 'Explorer Co', 'status' => 'active'],
    );
    app(TenantContext::class)->set((int) $tenant->id);
    $companyId = (int) $company->id;
    $suffix = Str::lower(Str::random(6));

    $unitA = PeopleReferenceEntry::query()->create([
        'company_id' => $companyId, 'type' => PeopleReferenceEntry::TYPE_ORGANIZATION_UNIT,
        'code' => 'explorer-alpha-'.$suffix, 'name' => 'Explorer Alpha',
        'status' => PeopleReferenceEntry::STATUS_ACTIVE,
    ]);
    $unitB = PeopleReferenceEntry::query()->create([
        'company_id' => $companyId, 'type' => PeopleReferenceEntry::TYPE_ORGANIZATION_UNIT,
        'code' => 'explorer-beta-'.$suffix, 'name' => 'Explorer Beta',
        'status' => PeopleReferenceEntry::STATUS_ACTIVE,
    ]);
    PeopleReferenceEntry::query()->create([
        'company_id' => $companyId, 'type' => PeopleReferenceEntry::TYPE_JOB_TITLE,
        'code' => 'explorer-vacant-'.$suffix, 'name' => 'Explorer Vacant Role',
        'status' => PeopleReferenceEntry::STATUS_ACTIVE, 'parent_id' => $unitA->id,
    ]);

    $typeA = DepartmentType::query()->create([
        'code' => 'explorer-a-'.$suffix, 'name' => 'Explorer Alpha Operations',
        'category' => 'operational', 'is_active' => true,
    ]);
    $typeB = DepartmentType::query()->create([
        'code' => 'explorer-b-'.$suffix, 'name' => 'Explorer Beta Operations',
        'category' => 'operational', 'is_active' => true,
    ]);

    $hod = Employee::factory()->create(['company_id' => $companyId, 'status' => 'active', 'short_name' => 'Explorer HOD']);
    $employee = Employee::factory()->create(['company_id' => $companyId, 'status' => 'active', 'short_name' => 'Explorer Employee']);
    $alphaExtra = Employee::factory()->count(2)->create(['company_id' => $companyId, 'status' => 'active']);
    $beta = Employee::factory()->create(['company_id' => $companyId, 'status' => 'active', 'short_name' => 'Explorer Beta Member']);

    $departmentA = Department::query()->create([
        'company_id' => $companyId, 'department_type_id' => $typeA->id,
        'head_id' => $hod->id, 'status' => 'active',
    ]);
    $departmentB = Department::query()->create([
        'company_id' => $companyId, 'department_type_id' => $typeB->id,
        'head_id' => $beta->id, 'status' => 'active',
    ]);

    foreach ([$hod, $employee, ...$alphaExtra] as $member) {
        $member->update(['department_id' => $departmentA->id]);
        EmployeeWorkProfile::query()->updateOrCreate(
            ['employee_id' => $member->id],
            ['organization_unit_id' => $unitA->id],
        );
    }
    $beta->update(['department_id' => $departmentB->id]);
    EmployeeWorkProfile::query()->updateOrCreate(
        ['employee_id' => $beta->id],
        ['organization_unit_id' => $unitB->id],
    );

    return [
        'tenant' => $tenant, 'company' => $company,
        'unitA' => $unitA, 'unitB' => $unitB,
        'hod' => $hod, 'employee' => $employee, 'beta' => $beta,
    ];
}

test('the explorer route requires the structure view capability', function (): void {
    $fixture = explorerFixture();
    $viewer = explorerUser((int) $fixture['company']->id);
    explorerGrant($viewer, 'people.organisation.structure.view');

    $this->actingAs($viewer)
        ->get(route('people.organisation.explorer.index'))
        ->assertOk();

    $stranger = User::factory()->create();
    $this->actingAs($stranger)
        ->get(route('people.organisation.explorer.index'))
        ->assertForbidden();
});

test('an hod sees only the assigned departments', function (): void {
    $fixture = explorerFixture();
    $hodUser = explorerUser((int) $fixture['company']->id, $fixture['hod']);
    explorerGrant($hodUser, 'people.organisation.structure.view');
    explorerGrant($hodUser, 'people.organisation.audience.hod');

    Livewire::actingAs($hodUser)
        ->test(Index::class)
        ->assertSee($fixture['unitA']->name)
        ->assertDontSee($fixture['unitB']->name);
});

test('an employee sees the permitted directory only', function (): void {
    $fixture = explorerFixture();
    $user = explorerUser((int) $fixture['company']->id, $fixture['employee']);
    explorerGrant($user, 'people.organisation.structure.view');
    explorerGrant($user, 'people.organisation.audience.employee');

    Livewire::actingAs($user)
        ->test(Index::class)
        ->assertSee($fixture['unitA']->name)
        ->assertDontSee($fixture['unitB']->name)
        ->assertDontSee($fixture['beta']->displayName());
});

test('an aggregate-only holder sees badges without drill-through', function (): void {
    $fixture = explorerFixture();
    $holder = explorerUser((int) $fixture['company']->id);
    explorerGrant($holder, 'people.organisation.structure.view');
    explorerGrant($holder, 'people.organisation.aggregate.view');
    explorerGrant($holder, 'people.organisation.audience.executive');

    Livewire::actingAs($holder)
        ->test(Index::class)
        ->assertSee('5 people')
        ->call('showDetail', WorkforceResourceType::Company->value, (string) $fixture['company']->id)
        ->assertDontSee($fixture['hod']->displayName())
        ->assertDontSee($fixture['employee']->displayName());
});

test('a detail holder drills through to permitted employees', function (): void {
    $fixture = explorerFixture();
    $holder = explorerUser((int) $fixture['company']->id);
    explorerGrant($holder, 'people.organisation.structure.view');
    explorerGrant($holder, 'people.organisation.aggregate.view');
    explorerGrant($holder, 'people.organisation.detail.view');
    explorerGrant($holder, 'people.organisation.audience.executive');

    Livewire::actingAs($holder)
        ->test(Index::class)
        ->call('toggle', WorkforceResourceType::Company->value, (string) $fixture['company']->id)
        ->assertSee($fixture['unitA']->name)
        ->assertSee($fixture['unitB']->name)
        ->call('showDetail', WorkforceResourceType::Company->value, (string) $fixture['company']->id)
        ->assertSee($fixture['hod']->displayName());
});

test('a historical as-of date renders no nodes', function (): void {
    $fixture = explorerFixture();
    $holder = explorerUser((int) $fixture['company']->id);
    explorerGrant($holder, 'people.organisation.structure.view');
    explorerGrant($holder, 'people.organisation.audience.executive');

    Livewire::actingAs($holder)
        ->test(Index::class)
        ->call('setAsOf', now()->subDay()->toDateString())
        ->assertDontSee($fixture['unitA']->name)
        ->assertSee(__('not available'));
});
