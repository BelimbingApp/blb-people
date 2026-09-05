<?php

use App\Base\Authz\Enums\PrincipalType;
use App\Base\Authz\Exceptions\AuthorizationDeniedException;
use App\Base\Authz\Models\PrincipalRole;
use App\Base\Authz\Models\Role;
use App\Base\Tenancy\Contracts\TenantContext;
use App\Core\Company\Models\Company;
use App\Core\User\Models\User;
use App\Domains\People\Progression\Enums\ProgressionPolicyStatus;
use App\Domains\People\Progression\Exceptions\ProgressionPolicyPublicationException;
use App\Domains\People\Progression\Models\ProgressionPolicy;
use App\Domains\People\Progression\Services\ProgressionPolicyPublisher;
use App\Domains\People\Skills\Exceptions\MissingCompanyScopeException;

afterEach(function (): void {
    app(TenantContext::class)->clear();
});

/** @return array{tenant: int, company: int, hr: User, hod: User} */
function publicationFixture(): array
{
    [$tenant, $company] = createTenantWithCompany(['name' => 'Publication Tenant'], ['name' => 'Publication Company', 'status' => 'active']);
    $tenantId = (int) $tenant->id;
    app(TenantContext::class)->set($tenantId);
    setupAuthzRoles();

    $users = [];
    foreach (['hr' => 'people_hr', 'hod' => 'people_hod'] as $key => $code) {
        $user = User::factory()->create(['company_id' => $company->id]);
        PrincipalRole::query()->create([
            'company_id' => $company->id,
            'principal_type' => PrincipalType::USER->value,
            'principal_id' => $user->id,
            'role_id' => Role::query()->whereNull('company_id')->where('code', $code)->valueOrFail('id'),
        ]);
        $users[$key] = $user;
    }

    return ['tenant' => $tenantId, 'company' => (int) $company->id, 'hr' => $users['hr'], 'hod' => $users['hod']];
}

/** @param array<string, mixed> $attributes */
function publicationDraft(int $tenantId, int $companyId, string $version = '2026.1', array $attributes = []): ProgressionPolicy
{
    return ProgressionPolicy::query()->create(array_replace([
        'tenant_id' => $tenantId,
        'company_entity_id' => $companyId,
        'policy_id' => 'technical-progression',
        'version' => $version,
        'status' => ProgressionPolicyStatus::Draft,
        'effective_from' => '2026-01-01',
        'rules' => ['tiers' => ['junior', 'senior']],
    ], $attributes));
}

it('publishes a draft and supersedes the previously published version of the same policy only', function (): void {
    ['tenant' => $tenantId, 'company' => $companyId, 'hr' => $hr] = publicationFixture();
    $first = publicationDraft($tenantId, $companyId, '2026.1');
    $second = publicationDraft($tenantId, $companyId, '2026.2');
    $otherPolicy = publicationDraft($tenantId, $companyId, '1', ['policy_id' => 'management-track']);
    $publisher = app(ProgressionPolicyPublisher::class);

    $published = $publisher->publish($hr, $companyId, (int) $first->id);
    $publisher->publish($hr, $companyId, (int) $otherPolicy->id);

    expect($published->status)->toBe(ProgressionPolicyStatus::Published)
        ->and($published->published_at)->not->toBeNull()
        ->and((int) $published->published_by_user_id)->toBe((int) $hr->id);

    $publisher->publish($hr, $companyId, (int) $second->id);

    expect($first->refresh()->status)->toBe(ProgressionPolicyStatus::Superseded)
        ->and($first->superseded_at)->not->toBeNull()
        ->and($second->refresh()->status)->toBe(ProgressionPolicyStatus::Published)
        ->and($otherPolicy->refresh()->status)->toBe(ProgressionPolicyStatus::Published);
});

it('refuses to publish without a tenant context', function (): void {
    ['tenant' => $tenantId, 'company' => $companyId, 'hr' => $hr] = publicationFixture();
    $draft = publicationDraft($tenantId, $companyId);
    app(TenantContext::class)->clear();

    expect(fn () => app(ProgressionPolicyPublisher::class)->publish($hr, $companyId, (int) $draft->id))
        ->toThrow(ProgressionPolicyPublicationException::class, 'tenant context is required');

    app(TenantContext::class)->set($tenantId);
    expect($draft->refresh()->status)->toBe(ProgressionPolicyStatus::Draft);
});

it('refuses to publish without the manage capability', function (): void {
    ['tenant' => $tenantId, 'company' => $companyId, 'hod' => $hod] = publicationFixture();
    $draft = publicationDraft($tenantId, $companyId);

    expect(fn () => app(ProgressionPolicyPublisher::class)->publish($hod, $companyId, (int) $draft->id))
        ->toThrow(AuthorizationDeniedException::class);

    expect($draft->refresh()->status)->toBe(ProgressionPolicyStatus::Draft);
});

it('refuses to publish for a company the actor is not attributed to, and a policy outside the company', function (): void {
    ['tenant' => $tenantId, 'company' => $companyId, 'hr' => $hr] = publicationFixture();
    $sibling = (int) Company::factory()->create(['tenant_id' => $tenantId, 'status' => 'active'])->id;
    $siblingDraft = publicationDraft($tenantId, $sibling);
    $publisher = app(ProgressionPolicyPublisher::class);

    expect(fn () => $publisher->publish($hr, $sibling, (int) $siblingDraft->id))
        ->toThrow(ProgressionPolicyPublicationException::class, 'may not publish policies for this company');

    // Naming the actor's own company does not let the row lookup reach the sibling's draft.
    expect(fn () => $publisher->publish($hr, $companyId, (int) $siblingDraft->id))
        ->toThrow(ProgressionPolicyPublicationException::class, 'not found in this company');

    expect($siblingDraft->refresh()->status)->toBe(ProgressionPolicyStatus::Draft);
});

it('refuses to publish anything but a draft', function (): void {
    ['tenant' => $tenantId, 'company' => $companyId, 'hr' => $hr] = publicationFixture();
    $published = publicationDraft($tenantId, $companyId, '2026.1', ['status' => ProgressionPolicyStatus::Published, 'published_at' => now()]);
    $superseded = publicationDraft($tenantId, $companyId, '2025.1', ['status' => ProgressionPolicyStatus::Superseded, 'superseded_at' => now()]);
    $publisher = app(ProgressionPolicyPublisher::class);

    expect(fn () => $publisher->publish($hr, $companyId, (int) $published->id))
        ->toThrow(ProgressionPolicyPublicationException::class, 'this version is published')
        ->and(fn () => $publisher->publish($hr, $companyId, (int) $superseded->id))
        ->toThrow(ProgressionPolicyPublicationException::class, 'this version is superseded');

    expect($published->refresh()->status)->toBe(ProgressionPolicyStatus::Published)
        ->and($superseded->refresh()->status)->toBe(ProgressionPolicyStatus::Superseded);
});

it('is a company-owned table: a query that does not pin the company refuses to run', function (): void {
    ['tenant' => $tenantId, 'company' => $companyId] = publicationFixture();
    publicationDraft($tenantId, $companyId);

    expect(fn () => ProgressionPolicy::query()->forTenant($tenantId)->get())
        ->toThrow(MissingCompanyScopeException::class, 'is company-owned');

    expect(ProgressionPolicy::query()->forCompany($tenantId, $companyId)->count())->toBe(1);
});
