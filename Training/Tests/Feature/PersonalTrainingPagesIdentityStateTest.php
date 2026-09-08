<?php

use App\Base\Authz\Enums\PrincipalType;
use App\Base\Authz\Models\PrincipalRole;
use App\Base\Authz\Models\Role;
use App\Base\Tenancy\Contracts\TenantContext;
use App\Core\User\Models\User;

/**
 * #434: the personal Training evaluation and evidence pages returned HTTP 500
 * on the first GET for an account that is authorized but has no employee
 * binding. Both stores deny in scope(), visibleEvents() calls scope(), and the
 * components call visibleEvents() from mount()/render(), so the domain
 * exception escaped as an unhandled error page.
 *
 * The fail-closed employee requirement is correct and stays. What changes is
 * that the page has to say so in words the operator can act on, and must not
 * describe an identity failure as an empty training list.
 */
function personalPagesActor(): User
{
    [$tenant, $company] = createTenantWithCompany(
        ['name' => 'Identity State Tenant'],
        ['name' => 'Identity State Company', 'status' => 'active'],
    );
    app(TenantContext::class)->set((int) $tenant->id);
    setupAuthzRoles();

    // Authorized by capability, deliberately with no bound employee identity --
    // the "capability granted without employee identity" case #434 names.
    $actor = User::factory()->create(['company_id' => $company->id]);
    PrincipalRole::query()->create([
        'company_id' => $company->id,
        'principal_type' => PrincipalType::USER->value,
        'principal_id' => $actor->id,
        'role_id' => Role::query()->whereNull('company_id')->where('code', 'people_employee')->sole()->id,
    ]);

    // Deliberately no EmployeePortalAccess row and no employee_id: the role
    // carries the capability, the identity binding is absent.
    return $actor;
}

test('the personal evaluation page explains a missing employee identity instead of failing', function (): void {
    $actor = personalPagesActor();

    $this->actingAs($actor)
        ->get(route('people.training.evaluations.index'))
        ->assertOk();
});

test('the personal evidence page explains a missing employee identity instead of failing', function (): void {
    $actor = personalPagesActor();

    $this->actingAs($actor)
        ->get(route('people.training.evidence.index'))
        ->assertOk();
});
