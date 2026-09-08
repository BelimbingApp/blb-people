<?php

use App\Base\Authz\Enums\PrincipalType;
use App\Base\Authz\Models\PrincipalRole;
use App\Base\Authz\Models\Role;
use App\Base\Menu\Contracts\MenuAccessChecker;
use App\Base\Menu\MenuItem;
use App\Base\Tenancy\Contracts\TenantContext;
use App\Core\Employee\Models\Employee;
use App\Core\User\Models\User;
use App\Domains\People\Settings\Models\EmployeePortalAccess;
use App\Domains\People\Skills\Models\SkillActorBinding;

afterEach(function (): void {
    app(TenantContext::class)->clear();
});

function trainingMenuActor(int $companyId, string $roleCode, ?int $employeeId = null): User
{
    setupAuthzRoles();

    $actor = User::factory()->create([
        'company_id' => $companyId,
        'employee_id' => $employeeId,
    ]);
    PrincipalRole::query()->create([
        'company_id' => $companyId,
        'principal_type' => PrincipalType::USER->value,
        'principal_id' => $actor->id,
        'role_id' => Role::query()->whereNull('company_id')->where('code', $roleCode)->sole()->id,
    ]);

    return $actor;
}

/** @return array<string, MenuItem> */
function trainingMenuItems(): array
{
    return collect([
        ...(require __DIR__.'/../../Config/menu.php')['items'],
        ...(require __DIR__.'/../../../Employees/Config/menu.php')['items'],
    ])->mapWithKeys(fn (array $item): array => [$item['id'] => MenuItem::fromArray($item)])->all();
}

test('HR governance navigation is offered only to the HR audience', function (): void {
    [$tenant, $company] = createTenantWithCompany(
        ['name' => 'Training menu tenant'],
        ['name' => 'Training menu company'],
    );
    app(TenantContext::class)->set((int) $tenant->id);

    $platformAdmin = trainingMenuActor((int) $company->id, 'core_admin');
    $hr = trainingMenuActor((int) $company->id, 'people_hr');
    $item = trainingMenuItems()['people.hr-governance'];
    $checker = app(MenuAccessChecker::class);

    expect($checker->canView($item, $platformAdmin))->toBeFalse()
        ->and($checker->canView($item, $hr))->toBeTrue();

    $this->actingAs($platformAdmin)
        ->get(route('people.hr-governance.index'))
        ->assertForbidden();
    $this->actingAs($hr)
        ->get(route('people.hr-governance.index'))
        ->assertOk();
});

test('personal passport navigation follows the signed-in employee binding', function (): void {
    [$tenant, $company] = createTenantWithCompany(
        ['name' => 'Passport menu tenant'],
        ['name' => 'Passport menu company'],
    );
    app(TenantContext::class)->set((int) $tenant->id);

    $unlinked = trainingMenuActor((int) $company->id, 'people_employee');
    $employee = Employee::factory()->create([
        'company_id' => $company->id,
        'full_name' => 'Linked passport employee',
        'status' => 'active',
    ]);
    $linked = trainingMenuActor((int) $company->id, 'people_employee', (int) $employee->id);
    EmployeePortalAccess::query()->create([
        'employee_id' => $employee->id,
        'user_id' => $linked->id,
        'display_name' => $employee->displayName(),
        'status' => EmployeePortalAccess::STATUS_ACTIVE,
    ]);
    SkillActorBinding::query()->create([
        'tenant_id' => $tenant->id,
        'company_entity_id' => $company->id,
        'platform_user_id' => $linked->id,
        'employee_entity_id' => $employee->id,
        'user_entity_id' => $linked->id,
        'confirmed_by_user_id' => $linked->id,
        'review_reference' => 'training-menu-passport',
        'confirmed_at' => now(),
    ]);

    $item = trainingMenuItems()['people.training-passport'];
    $checker = app(MenuAccessChecker::class);

    expect($checker->canView($item, $unlinked))->toBeFalse()
        ->and($checker->canView($item, $linked))->toBeTrue();

    $this->actingAs($unlinked)
        ->get(route('people.training.passport'))
        ->assertForbidden();
    $this->actingAs($linked)
        ->get(route('people.training.passport'))
        ->assertOk();
});
