<?php

use App\Base\Authz\Enums\PrincipalType;
use App\Base\Authz\Exceptions\AuthorizationDeniedException;
use App\Base\Authz\Models\PrincipalRole;
use App\Base\Authz\Models\Role;
use App\Base\Menu\Contracts\MenuAccessChecker;
use App\Base\Menu\MenuItem;
use App\Base\Tenancy\Contracts\TenantContext;
use App\Core\Company\Models\Company;
use App\Core\Company\Models\Department;
use App\Core\Company\Models\DepartmentType;
use App\Core\Employee\Models\Employee;
use App\Core\User\Models\User;
use App\Domains\People\Provider\Enums\WorkforceResourceType;
use App\Domains\People\Settings\Models\EmployeePortalAccess;
use App\Domains\People\Settings\Models\EmployeeWorkProfile;
use App\Domains\People\Skills\Exceptions\InvalidSkillAudienceAssignmentException;
use App\Domains\People\Skills\Livewire\Assessment\Matrix;
use App\Domains\People\Skills\Models\SkillActorBinding;
use App\Domains\People\Skills\Services\SkillAudience;
use App\Domains\People\Skills\Services\SkillAudienceAssignmentStore;
use App\Domains\People\Skills\Tests\Support\NativeWorkforceFixture;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->withoutVite();
});

afterEach(function (): void {
    app(TenantContext::class)->clear();
});

function skillAudienceRole(User $user, string $code): void
{
    setupAuthzRoles();
    $role = Role::query()->whereNull('company_id')->where('code', $code)->sole();

    PrincipalRole::query()->create([
        'company_id' => $user->company_id,
        'principal_type' => PrincipalType::USER->value,
        'principal_id' => $user->id,
        'role_id' => $role->id,
    ]);
}

/** @return array{int, null} */
function skillAudienceCompany(int $tenantId, Company $platformCompany, string $name): array
{
    $platformCompany->update(['name' => $name]);

    return [(int) $platformCompany->id, null];
}

function skillAudienceEntity(int $tenantId, string $type): int
{
    return (int) NativeWorkforceFixture::create($tenantId, WorkforceResourceType::from($type))->id;
}

function skillAudienceEmployee(
    int $tenantId,
    int $companyEntityId,
    mixed $connection,
    string $name,
    ?int $organizationEntityId = null,
    ?int $managerEntityId = null,
    ?int $departmentHeadEntityId = null,
): Employee {
    $employee = Employee::factory()->create([
        'company_id' => $companyEntityId,
        'full_name' => $name,
        'short_name' => null,
        'supervisor_id' => $managerEntityId,
        'status' => 'active',
    ]);

    if ($organizationEntityId !== null) {
        EmployeeWorkProfile::query()->updateOrCreate(
            ['employee_id' => $employee->id],
            ['organization_unit_id' => $organizationEntityId],
        );
    }

    if ($departmentHeadEntityId !== null) {
        $type = DepartmentType::query()->create([
            'code' => 'audience-'.$employee->id,
            'name' => 'Audience '.$employee->id,
            'category' => 'operational',
            'is_active' => true,
        ]);
        $department = Department::query()->create([
            'company_id' => $companyEntityId,
            'department_type_id' => $type->id,
            'head_id' => $departmentHeadEntityId,
            'status' => 'active',
        ]);
        $employee->update(['department_id' => $department->id]);
    }

    return $employee;
}

function skillAudienceBindUser(User $user, Employee $employee): void
{
    $user->update(['employee_id' => $employee->id]);
    EmployeePortalAccess::query()->updateOrCreate(
        ['employee_id' => $employee->id],
        [
            'user_id' => $user->id,
            'display_name' => $employee->displayName(),
            'status' => EmployeePortalAccess::STATUS_ACTIVE,
        ],
    );
}

test('platform administration does not implicitly become connector HR', function (): void {
    [$tenant, $company] = createTenantWithCompany(['name' => 'Audience Tenant'], ['name' => 'Audience Company']);
    app(TenantContext::class)->set((int) $tenant->id);
    [$companyEntityId, $connection] = skillAudienceCompany((int) $tenant->id, $company, 'Audience Workforce');
    $first = skillAudienceEmployee((int) $tenant->id, $companyEntityId, $connection, 'First Worker');
    $second = skillAudienceEmployee((int) $tenant->id, $companyEntityId, $connection, 'Second Worker');

    $hr = User::factory()->create(['company_id' => $company->id]);
    skillAudienceRole($hr, 'people_hr');

    $platformAdmin = User::factory()->create(['company_id' => $company->id]);
    skillAudienceRole($platformAdmin, 'core_admin');

    expect(app(SkillAudience::class)->visibleEmployeeEntityIds($hr, $companyEntityId, manage: true))
        ->toEqualCanonicalizing([(int) $first->id, (int) $second->id])
        ->and(app(SkillAudience::class)->visibleDevelopmentActionEmployeeEntityIds($hr, $companyEntityId, manage: true))
        ->toEqualCanonicalizing([(int) $first->id, (int) $second->id])
        ->and(fn () => app(SkillAudience::class)->authorizeAudience(
            $platformAdmin,
            'people.skill.development-action.view',
        ))->toThrow(AuthorizationDeniedException::class);

    $this->actingAs($platformAdmin)
        ->get(route('people.skill.assessment.matrix'))
        ->assertForbidden();
});

test('Skills menus are visible only to their deep People audiences', function (): void {
    [$tenant, $company] = createTenantWithCompany(
        ['name' => 'Menu Audience Tenant'],
        ['name' => 'Menu Audience Company'],
    );
    app(TenantContext::class)->set((int) $tenant->id);

    $users = collect([
        'hr' => 'people_hr',
        'hod' => 'people_hod',
        'assessor' => 'people_assessor',
        'employee' => 'people_employee',
        'platform' => 'core_admin',
    ])->map(function (string $role) use ($company): User {
        $user = User::factory()->create(['company_id' => $company->id]);
        skillAudienceRole($user, $role);

        return $user;
    });

    $skillItems = collect((require __DIR__.'/../../Config/menu.php')['items'])
        ->mapWithKeys(fn (array $item): array => [$item['id'] => MenuItem::fromArray($item)]);
    $checker = app(MenuAccessChecker::class);

    expect($checker->canView($skillItems->get('people.skills'), $users->get('platform')))->toBeFalse()
        ->and($checker->canView($skillItems->get('people.skill-assessments'), $users->get('platform')))->toBeFalse()
        ->and($checker->canView($skillItems->get('people.skills'), $users->get('hr')))->toBeTrue()
        ->and($checker->canView($skillItems->get('people.skill-assessments'), $users->get('hr')))->toBeTrue()
        ->and($checker->canView($skillItems->get('people.skills'), $users->get('hod')))->toBeTrue()
        ->and($checker->canView($skillItems->get('people.skill-assessments'), $users->get('hod')))->toBeTrue()
        ->and($checker->canView($skillItems->get('people.skills'), $users->get('assessor')))->toBeTrue()
        ->and($checker->canView($skillItems->get('people.skill-assessments'), $users->get('assessor')))->toBeTrue()
        ->and($checker->canView($skillItems->get('people.skills'), $users->get('employee')))->toBeTrue()
        ->and($checker->canView($skillItems->get('people.skill-assessments'), $users->get('employee')))->toBeTrue();
});

test('HOD assessor and employee audiences resolve department assignment and self without sibling leakage', function (): void {
    [$tenant, $companyA] = createTenantWithCompany(['name' => 'Scoped Audience Tenant'], ['name' => 'Company A']);
    $companyB = Company::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Company B']);
    app(TenantContext::class)->set((int) $tenant->id);
    [$companyAEntityId, $connectionA] = skillAudienceCompany((int) $tenant->id, $companyA, 'Workforce A');
    [$companyBEntityId, $connectionB] = skillAudienceCompany((int) $tenant->id, $companyB, 'Workforce B');

    $departmentA = skillAudienceEntity((int) $tenant->id, 'organization_unit');
    $departmentB = skillAudienceEntity((int) $tenant->id, 'organization_unit');
    $head = skillAudienceEmployee((int) $tenant->id, $companyAEntityId, $connectionA, 'Department Head', $departmentA);
    $teamWorker = skillAudienceEmployee(
        (int) $tenant->id,
        $companyAEntityId,
        $connectionA,
        'Team Worker',
        $departmentA,
        (int) $head->id,
        (int) $head->id,
    );
    $otherDepartment = skillAudienceEmployee(
        (int) $tenant->id,
        $companyAEntityId,
        $connectionA,
        'Other Department',
        $departmentB,
    );
    $siblingCompany = skillAudienceEmployee((int) $tenant->id, $companyBEntityId, $connectionB, 'Sibling Company Worker');

    $hr = User::factory()->create(['company_id' => $companyA->id]);
    $hod = User::factory()->create(['company_id' => $companyA->id]);
    $assessor = User::factory()->create(['company_id' => $companyA->id]);
    $employee = User::factory()->create(['company_id' => $companyA->id]);
    skillAudienceRole($hr, 'people_hr');
    skillAudienceRole($hod, 'people_hod');
    skillAudienceRole($assessor, 'people_assessor');
    skillAudienceRole($employee, 'people_employee');
    skillAudienceBindUser($hod, $head);
    skillAudienceBindUser($employee, $teamWorker);

    $assignments = app(SkillAudienceAssignmentStore::class);
    $assignments->confirmActor($hr, $hod, $companyAEntityId, (int) $head->id, 'review:hod-link');
    $assignments->confirmActor($hr, $employee, $companyAEntityId, (int) $teamWorker->id, 'review:self-link');
    $assignments->assignAssessor(
        $hr,
        $assessor,
        $companyAEntityId,
        (int) $teamWorker->id,
        'review:assessor-assignment',
    );
    expect(fn () => $assignments->assignAssessor(
        $hr,
        $assessor,
        $companyAEntityId,
        (int) $siblingCompany->id,
        'review:invalid-sibling-assignment',
    ))->toThrow(InvalidSkillAudienceAssignmentException::class);

    $audience = app(SkillAudience::class);
    expect($audience->visibleEmployeeEntityIds($hod, $companyAEntityId, manage: true))
        ->toBe([(int) $teamWorker->id])
        ->and($audience->visibleDevelopmentActionEmployeeEntityIds($hod, $companyAEntityId, manage: true))
        ->toBe([(int) $teamWorker->id])
        ->and($audience->visibleEmployeeEntityIds($assessor, $companyAEntityId, manage: true))
        ->toBe([(int) $teamWorker->id])
        ->and($audience->visibleEmployeeEntityIds($employee, $companyAEntityId, manage: false))
        ->toBe([(int) $teamWorker->id])
        ->and($audience->visibleEmployeeEntityIds($hod, $companyBEntityId, manage: true))
        ->toBe([])
        ->and($audience->visibleDevelopmentActionEmployeeEntityIds($hod, $companyBEntityId, manage: true))
        ->toBe([])
        ->and($audience->allowedCompanies($hr, 'people.skill.assessment.view'))
        ->toBe([$companyAEntityId => 'Workforce A']);

    $audience->authorizeAssessmentSubmission($assessor, $companyAEntityId, (int) $teamWorker->id);
    $audience->authorizeHodVerification($hod, $companyAEntityId, (int) $teamWorker->id);
    $audience->authorizeAssessmentFinalization($hod, $companyAEntityId, (int) $teamWorker->id);

    expect(fn () => $audience->authorizeHodVerification(
        $assessor,
        $companyAEntityId,
        (int) $teamWorker->id,
    ))->toThrow(AuthorizationDeniedException::class);

    expect(fn () => $audience->authorizeHodVerification(
        $hod,
        $companyAEntityId,
        (int) $otherDepartment->id,
    ))->toThrow(AuthorizationDeniedException::class);

    expect(fn () => $audience->visibleEmployeeEntityIds($employee, $companyAEntityId, manage: true))
        ->toThrow(AuthorizationDeniedException::class);

    expect($otherDepartment->id)->not->toBe($teamWorker->id)
        ->and($siblingCompany->id)->not->toBe($teamWorker->id);

    Livewire::actingAs($hod)
        ->test(Matrix::class)
        ->assertViewHas('employees', fn ($employees): bool => $employees->pluck('display_name')->all() === ['Team Worker']);

    Livewire::actingAs($assessor)
        ->test(Matrix::class)
        ->assertViewHas('employees', fn ($employees): bool => $employees->pluck('display_name')->all() === ['Team Worker']);

    Livewire::actingAs($employee)
        ->test(Matrix::class)
        ->assertViewHas('employees', fn ($employees): bool => $employees->pluck('display_name')->all() === ['Team Worker']);
});

test('revocation and tenant changes invalidate a previously confirmed self binding', function (): void {
    [$tenantA, $companyA] = createTenantWithCompany(['name' => 'Binding Tenant A'], ['name' => 'Binding Company A']);
    app(TenantContext::class)->set((int) $tenantA->id);
    [$companyEntityId, $connection] = skillAudienceCompany((int) $tenantA->id, $companyA, 'Binding Workforce');
    $worker = skillAudienceEmployee((int) $tenantA->id, $companyEntityId, $connection, 'Bound Worker');
    $hr = User::factory()->create(['company_id' => $companyA->id]);
    $employee = User::factory()->create(['company_id' => $companyA->id]);
    skillAudienceRole($hr, 'people_hr');
    skillAudienceRole($employee, 'people_employee');
    skillAudienceBindUser($employee, $worker);

    $store = app(SkillAudienceAssignmentStore::class);
    $store->confirmActor($hr, $employee, $companyEntityId, (int) $worker->id, 'review:binding');
    expect(app(SkillAudience::class)->visibleEmployeeEntityIds($employee, $companyEntityId, manage: false))
        ->toBe([(int) $worker->id]);

    $store->revokeActor($hr, $companyEntityId, (int) $employee->id, 'review:revocation');
    $revoked = SkillActorBinding::query()
        ->forCompany((int) $tenantA->id, $companyEntityId)
        ->where('platform_user_id', $employee->id)
        ->sole();
    expect($revoked->revoked_by_user_id)->toBe((int) $hr->id)
        ->and($revoked->revocation_reference)->toBe('review:revocation')
        ->and(app(SkillAudience::class)->visibleEmployeeEntityIds($employee, $companyEntityId, manage: false))->toBe([]);

    $store->confirmActor($hr, $employee, $companyEntityId, (int) $worker->id, 'review:reconfirmed');

    EmployeePortalAccess::query()
        ->where('employee_id', $worker->id)
        ->update(['status' => EmployeePortalAccess::STATUS_REVOKED]);
    expect(app(SkillAudience::class)->visibleEmployeeEntityIds($employee, $companyEntityId, manage: false))->toBe([]);

    $tenantB = createTenant(['name' => 'Binding Tenant B']);
    app(TenantContext::class)->set((int) $tenantB->id);
    expect(app(SkillAudience::class)->visibleEmployeeEntityIds($employee, $companyEntityId, manage: false))->toBe([])
        ->and(app(SkillAudience::class)->visibleDevelopmentActionEmployeeEntityIds($hr, $companyEntityId, manage: true))
        ->toBe([]);
});
