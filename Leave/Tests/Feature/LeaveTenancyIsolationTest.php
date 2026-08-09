<?php

use App\Base\Authz\Contracts\AuthorizationService;
use App\Base\Authz\DTO\Actor;
use App\Base\Authz\Enums\AuthorizationReasonCode;
use App\Base\Authz\Enums\PrincipalType;
use App\Base\Authz\Models\PrincipalRole;
use App\Base\Authz\Models\Role;
use App\Base\Authz\Services\AuthorizationEngine;
use App\Core\User\Models\User;
use App\Domains\People\Leave\Models\LeaveType;

/**
 * Consumer proof: the People domain composes with platform tenancy without
 * any Domain code change. Leave types carry company_id only — tenant
 * isolation arrives through Authz tenant enrichment, not Domain schema.
 */
beforeEach(function (): void {
    setupAuthzRoles();
});

function createLeaveTypeFor(int $companyId, string $code): LeaveType
{
    return LeaveType::query()->create([
        'company_id' => $companyId,
        'code' => $code,
        'name' => $code,
        'paid' => true,
        'default_unit' => LeaveType::UNIT_DAY,
        'status' => LeaveType::STATUS_ACTIVE,
    ]);
}

it('enriches company-owned leave types and denies cross-tenant authorization', function (): void {
    [$tenantA, $companyA] = createTenantWithCompany(['name' => 'Tenant A']);
    [$tenantB, $companyB] = createTenantWithCompany(['name' => 'Tenant B']);

    $userA = User::factory()->create(['company_id' => $companyA->id]);
    grantPeopleLeaveManage($userA->id, $companyA->id);

    // Both tenants can use the same domain-local code within their own company.
    $own = createLeaveTypeFor($companyA->id, 'ANNUAL');
    $foreign = createLeaveTypeFor($companyB->id, 'ANNUAL');

    $authz = app(AuthorizationService::class);
    $engine = app(AuthorizationEngine::class);
    $actorA = Actor::forUser($userA);

    // The Domain model carries no tenant_id column: the engine enriches the
    // resource context from the owning company through the TenantDirectory.
    $foreignContext = $engine->resourceContext($foreign);
    $ownContext = $engine->resourceContext($own);

    expect($foreignContext->tenantId)->toBe($tenantB->id);
    expect($ownContext->tenantId)->toBe($tenantA->id);

    // Direct cross-tenant access is denied with the tenant scope reason.
    $decision = $authz->can($actorA, 'people.leave.manage', $foreignContext);

    expect($decision->allowed)->toBeFalse();
    expect($decision->reasonCode)->toBe(AuthorizationReasonCode::DENIED_TENANT_SCOPE);

    // Same-tenant access with the grant still works.
    expect($authz->can($actorA, 'people.leave.manage', $ownContext)->allowed)->toBeTrue();

    // Filtering returns exactly the tenant's own records.
    $allowed = $authz->filterAllowed($actorA, 'people.leave.manage', [$own, $foreign]);

    expect($allowed->all())->toHaveCount(1);
    expect($allowed->first()->id)->toBe($own->id);
});

/**
 * Grant the people leave manage capability to a user within their company.
 */
function grantPeopleLeaveManage(int $userId, int $companyId): void
{
    $role = Role::query()
        ->where('code', 'core_admin')
        ->whereNull('company_id')
        ->firstOrFail();

    PrincipalRole::query()->create([
        'company_id' => $companyId,
        'principal_type' => PrincipalType::USER->value,
        'principal_id' => $userId,
        'role_id' => $role->id,
    ]);
}
