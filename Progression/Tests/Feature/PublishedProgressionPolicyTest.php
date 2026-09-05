<?php

use App\Base\Foundation\ModuleManifest\ModuleManifestReader;
use App\Base\Tenancy\Contracts\TenantContext;
use App\Core\Company\Models\Company;
use App\Core\Employee\Models\Employee;
use App\Domains\People\Progression\Contracts\ReadsPublishedProgressionPolicy;
use App\Domains\People\Progression\Data\PublishedProgressionPolicy;
use App\Domains\People\Progression\Enums\ProgressionPolicyRefusal;
use App\Domains\People\Progression\Enums\ProgressionPolicyStatus;
use App\Domains\People\Progression\Models\ProgressionPolicy;
use App\Domains\People\Progression\Services\DatabasePublishedProgressionPolicyReader;
use App\Domains\People\Provider\Data\WorkforceSubject;
use App\Domains\People\Provider\Enums\WorkforceResourceType;
use Illuminate\Support\Carbon;

afterEach(function (): void {
    Carbon::setTestNow();
    app(TenantContext::class)->clear();
});

/** @param array<string, mixed> $attributes */
function progressionPolicyRow(int $tenantId, int $companyId, array $attributes = []): ProgressionPolicy
{
    return ProgressionPolicy::query()->create(array_replace([
        'tenant_id' => $tenantId,
        'company_entity_id' => $companyId,
        'policy_id' => 'technical-progression',
        'version' => '2026.1',
        'status' => ProgressionPolicyStatus::Published,
        'effective_from' => '2026-01-01',
        'rules' => ['tiers' => ['junior', 'senior']],
        'published_at' => '2026-01-01 00:00:00',
    ], $attributes));
}

function progressionPolicyFixture(): array
{
    [$tenant, $company] = createTenantWithCompany();
    app(TenantContext::class)->set((int) $tenant->id);
    $employee = Employee::factory()->create(['company_id' => $company->id, 'status' => 'active']);
    progressionPolicyRow((int) $tenant->id, (int) $company->id);

    return [$tenant, $company, $employee];
}

function progressionSubject(?int $tenantId, ?int $companyId, string $stableId): WorkforceSubject
{
    return new WorkforceSubject($tenantId, $companyId, WorkforceResourceType::Employee, $stableId);
}

it('discovers Progression and binds its published policy contract to the publication record', function (): void {
    $modules = (new ModuleManifestReader([base_path('app/Domains/People')]))->all();
    expect(collect($modules)->pluck('module')->all())->toContain('people/progression')
        ->and(app(ReadsPublishedProgressionPolicy::class))->toBeInstanceOf(DatabasePublishedProgressionPolicyReader::class);
});

it('returns only the published version for the resolved subjects company', function (): void {
    [$tenant, $company, $employee] = progressionPolicyFixture();
    // The sibling's policy is newer on both ordering keys, so a read that lost
    // its company scope would return it instead of the subject's own.
    $otherCompany = Company::factory()->create(['tenant_id' => $tenant->id, 'status' => 'active']);
    progressionPolicyRow((int) $tenant->id, (int) $otherCompany->id, [
        'policy_id' => 'other-policy', 'version' => '99', 'effective_from' => '2026-02-01', 'published_at' => '2026-02-01 00:00:00',
    ]);

    $result = app(ReadsPublishedProgressionPolicy::class)->read(progressionSubject((int) $tenant->id, (int) $company->id, (string) $employee->id));

    expect($result)->toEqual(new PublishedProgressionPolicy(
        (int) $tenant->id, (int) $company->id, 'technical-progression', '2026.1',
    ));
});

it('selects the latest effective-dated published version and never a draft, superseded, or future one', function (): void {
    Carbon::setTestNow('2026-06-15');
    [$tenant, $company, $employee] = progressionPolicyFixture();
    $tenantId = (int) $tenant->id;
    $companyId = (int) $company->id;
    progressionPolicyRow($tenantId, $companyId, ['version' => '2026.2', 'effective_from' => '2026-06-15', 'published_at' => '2026-06-01 00:00:00']);
    progressionPolicyRow($tenantId, $companyId, ['version' => '2026.3', 'effective_from' => '2026-06-16', 'published_at' => '2026-06-02 00:00:00']);
    progressionPolicyRow($tenantId, $companyId, ['version' => '2026.4', 'effective_from' => '2026-01-01', 'status' => ProgressionPolicyStatus::Draft, 'published_at' => null]);
    progressionPolicyRow($tenantId, $companyId, ['version' => '2025.9', 'effective_from' => '2025-01-01', 'status' => ProgressionPolicyStatus::Superseded]);

    $result = app(ReadsPublishedProgressionPolicy::class)->read(progressionSubject($tenantId, $companyId, (string) $employee->id));

    // 2026.2 is effective today (boundary day, whereDate); 2026.3 starts tomorrow.
    expect($result)->toEqual(new PublishedProgressionPolicy($tenantId, $companyId, 'technical-progression', '2026.2'));
});

it('refuses unsafe subject or policy selection with a typed reason', function (string $scenario, string $reason): void {
    [$tenant, $company, $employee] = progressionPolicyFixture();
    $tenantId = (int) $tenant->id;
    $companyId = (int) $company->id;
    $stableId = (string) $employee->id;

    match ($scenario) {
        'missing_context' => app(TenantContext::class)->clear(),
        'missing_tenant' => $tenantId = null,
        'wrong_tenant' => $tenantId += 1000,
        'missing_company' => $companyId = null,
        'wrong_company' => $companyId = (int) Company::factory()->create(['tenant_id' => $tenant->id])->id,
        'unknown_subject' => $stableId = 'unknown',
        'inactive_subject' => $employee->update(['status' => 'inactive']),
        'no_policy' => ProgressionPolicy::query()->forCompany($tenantId, $companyId)->update(['status' => ProgressionPolicyStatus::Superseded->value]),
        'not_yet_effective' => ProgressionPolicy::query()->forCompany($tenantId, $companyId)->update(['effective_from' => today()->addDay()]),
        'invalid_policy' => ProgressionPolicy::query()->forCompany($tenantId, $companyId)->update(['version' => ' ']),
    };

    // A valid policy must still be denied for a subject attributed to a sibling.
    if ($scenario === 'wrong_company') {
        progressionPolicyRow((int) $tenant->id, $companyId, ['policy_id' => 'sibling-policy', 'version' => '1']);
    }

    expect(app(ReadsPublishedProgressionPolicy::class)->read(progressionSubject($tenantId, $companyId, $stableId)))
        ->toBe(ProgressionPolicyRefusal::from($reason));
})->with([
    ['missing_context', 'missing_tenant'],
    ['missing_tenant', 'missing_tenant'],
    ['wrong_tenant', 'tenant_mismatch'],
    ['missing_company', 'wrong_company'],
    ['wrong_company', 'wrong_company'],
    ['unknown_subject', 'unknown_subject'],
    ['inactive_subject', 'deactivated_subject'],
    ['no_policy', 'no_policy_published'],
    ['not_yet_effective', 'no_policy_published'],
    ['invalid_policy', 'invalid_policy'],
]);
