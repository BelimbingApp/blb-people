<?php

use App\Base\Authz\Enums\PrincipalType;
use App\Base\Authz\Models\PrincipalCapability;
use App\Base\Tenancy\Contracts\TenantContext;
use App\Core\Company\Models\Company;
use App\Core\User\Models\User;
use App\Domains\People\Skills\Livewire\Assessment\Matrix;
use Livewire\Livewire;

afterEach(function (): void {
    app(TenantContext::class)->clear();
});

beforeEach(function (): void {
    $this->withoutVite();
});

function assessmentPageCompanyEntity(int $tenantId, string $name, ?int $platformCompanyId = null): int
{
    $company = $platformCompanyId === null
        ? Company::factory()->create(['tenant_id' => $tenantId, 'name' => $name, 'status' => 'active'])
        : Company::query()->forTenant($tenantId)->findOrFail($platformCompanyId);

    if ($platformCompanyId !== null && $company->name !== $name) {
        $company->update(['name' => $name]);
    }

    return (int) $company->id;
}

function assessmentPageGrantHr(User $user): void
{
    PrincipalCapability::query()->create([
        'company_id' => $user->company_id,
        'principal_type' => PrincipalType::USER->value,
        'principal_id' => $user->id,
        'capability_key' => 'people.skill.hr.view',
        'is_allowed' => true,
    ]);
}

test('the assessment matrix route requires the view capability', function (): void {
    $admin = createAdminUser();
    assessmentPageGrantHr($admin);
    $tenantId = (int) app(TenantContext::class)->currentTenantId();
    assessmentPageCompanyEntity($tenantId, 'Assess Co', (int) $admin->company_id);

    PrincipalCapability::query()->create([
        'company_id' => $admin->company_id,
        'principal_type' => PrincipalType::USER->value,
        'principal_id' => $admin->id,
        'capability_key' => 'people.skill.assessment.view',
        'is_allowed' => true,
    ]);
    $this->actingAs($admin)
        ->get(route('people.skill.assessment.matrix'))
        ->assertOk();

    $stranger = User::factory()->create(['company_id' => $admin->company_id]);
    $this->actingAs($stranger)
        ->get(route('people.skill.assessment.matrix'))
        ->assertForbidden();
});

test('saveMatrix refuses viewers without manage capability', function (): void {
    [$tenant, $company] = createTenantWithCompany(['name' => 'Matrix View Tenant'], ['name' => 'Matrix View Co']);
    app(TenantContext::class)->set((int) $tenant->id);

    $viewer = User::factory()->create(['company_id' => $company->id]);
    PrincipalCapability::query()->create([
        'company_id' => $company->id,
        'principal_type' => PrincipalType::USER->value,
        'principal_id' => $viewer->id,
        'capability_key' => 'people.skill.assessment.view',
        'is_allowed' => true,
    ]);
    PrincipalCapability::query()->create([
        'company_id' => $company->id,
        'principal_type' => PrincipalType::USER->value,
        'principal_id' => $viewer->id,
        'capability_key' => 'people.skill.employee.view',
        'is_allowed' => true,
    ]);
    assessmentPageCompanyEntity((int) $tenant->id, 'Matrix View Workforce', (int) $company->id);

    Livewire::actingAs($viewer)
        ->test(Matrix::class)
        ->call('saveMatrix')
        ->assertForbidden();
});
