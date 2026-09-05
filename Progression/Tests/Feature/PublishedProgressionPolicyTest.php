<?php

use App\Base\Foundation\ModuleManifest\ModuleManifestReader;
use App\Base\Tenancy\Contracts\TenantContext;
use App\Core\Company\Models\Company;
use App\Core\Employee\Models\Employee;
use App\Domains\People\Progression\Contracts\ReadsPublishedProgressionPolicy;
use App\Domains\People\Progression\Data\PublishedProgressionPolicy;
use App\Domains\People\Progression\Enums\ProgressionPolicyRefusal;
use App\Domains\People\Progression\Services\ConfigPublishedProgressionPolicyReader;
use App\Domains\People\Provider\Data\WorkforceSubject;
use App\Domains\People\Provider\Enums\WorkforceResourceType;

afterEach(function (): void {
    app(TenantContext::class)->clear();
});

function progressionPolicyFixture(): array
{
    [$tenant, $company] = createTenantWithCompany();
    app(TenantContext::class)->set((int) $tenant->id);
    $employee = Employee::factory()->create(['company_id' => $company->id, 'status' => 'active']);
    config()->set("people.progression.published_policies.{$tenant->id}.{$company->id}", [
        'policy_id' => 'technical-progression',
        'version' => '2026.1',
    ]);

    return [$tenant, $company, $employee];
}

it('discovers Progression and binds its published policy contract', function (): void {
    $modules = (new ModuleManifestReader([base_path('app/Domains/People')]))->all();
    expect(collect($modules)->pluck('module')->all())->toContain('people/progression')
        ->and(config('people.progression.published_policies'))->toBe([])
        ->and(app(ReadsPublishedProgressionPolicy::class))->toBeInstanceOf(ConfigPublishedProgressionPolicyReader::class);
});

it('returns only the published version for the resolved subjects company', function (): void {
    [$tenant, $company, $employee] = progressionPolicyFixture();
    $otherCompany = Company::factory()->create(['tenant_id' => $tenant->id, 'status' => 'active']);
    config()->set("people.progression.published_policies.{$tenant->id}.{$otherCompany->id}", [
        'policy_id' => 'other-policy', 'version' => '99',
    ]);

    $result = app(ReadsPublishedProgressionPolicy::class)->read(new WorkforceSubject(
        (int) $tenant->id, (int) $company->id, WorkforceResourceType::Employee, (string) $employee->id,
    ));

    expect($result)->toEqual(new PublishedProgressionPolicy(
        (int) $tenant->id, (int) $company->id, 'technical-progression', '2026.1',
    ));
});

it('refuses unsafe subject or policy selection with a typed reason', function (string $scenario, string $reason): void {
    [$tenant, $company, $employee] = progressionPolicyFixture();
    $tenantId = (int) $tenant->id;
    $companyId = (int) $company->id;
    $stableId = (string) $employee->id;
    $key = "people.progression.published_policies.{$tenantId}.{$companyId}";

    match ($scenario) {
        'missing_context' => app(TenantContext::class)->clear(),
        'missing_tenant' => $tenantId = null,
        'wrong_tenant' => $tenantId += 1000,
        'missing_company' => $companyId = null,
        'wrong_company' => $companyId = (int) Company::factory()->create(['tenant_id' => $tenant->id])->id,
        'unknown_subject' => $stableId = 'unknown',
        'inactive_subject' => $employee->update(['status' => 'inactive']),
        'no_policy' => config()->set($key, null),
        'invalid_policy' => config()->set($key, ['policy_id' => 'technical-progression', 'version' => '']),
    };

    // A valid policy must still be denied for a subject attributed to a sibling.
    if ($scenario === 'wrong_company') {
        config()->set("people.progression.published_policies.{$tenant->id}.{$companyId}", [
            'policy_id' => 'sibling-policy', 'version' => '1',
        ]);
    }

    expect(app(ReadsPublishedProgressionPolicy::class)->read(new WorkforceSubject(
        $tenantId, $companyId, WorkforceResourceType::Employee, $stableId,
    )))->toBe(ProgressionPolicyRefusal::from($reason));
})->with([
    ['missing_context', 'missing_tenant'],
    ['missing_tenant', 'missing_tenant'],
    ['wrong_tenant', 'tenant_mismatch'],
    ['missing_company', 'wrong_company'],
    ['wrong_company', 'wrong_company'],
    ['unknown_subject', 'unknown_subject'],
    ['inactive_subject', 'deactivated_subject'],
    ['no_policy', 'no_policy_published'],
    ['invalid_policy', 'invalid_policy'],
]);

it('refuses malformed publication metadata instead of inventing a version', function (mixed $policy): void {
    [$tenant, $company, $employee] = progressionPolicyFixture();
    config()->set("people.progression.published_policies.{$tenant->id}.{$company->id}", $policy);

    expect(app(ReadsPublishedProgressionPolicy::class)->read(new WorkforceSubject(
        (int) $tenant->id, (int) $company->id, WorkforceResourceType::Employee, (string) $employee->id,
    )))->toBe(ProgressionPolicyRefusal::InvalidPolicy);
})->with([
    'not a policy record' => [false],
    'missing policy identity' => [['version' => '1']],
    'numeric policy identity' => [['policy_id' => 1, 'version' => '1']],
    'blank policy identity' => [['policy_id' => ' ', 'version' => '1']],
    'missing version' => [['policy_id' => 'policy']],
    'numeric version' => [['policy_id' => 'policy', 'version' => 1]],
    'blank version' => [['policy_id' => 'policy', 'version' => ' ']],
]);
