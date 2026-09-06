<?php

use App\Base\Authz\Enums\PrincipalType;
use App\Base\Authz\Exceptions\AuthorizationDeniedException;
use App\Base\Authz\Models\PrincipalRole;
use App\Base\Authz\Models\Role;
use App\Base\Tenancy\Contracts\TenantContext;
use App\Core\Company\Models\Company;
use App\Core\User\Models\User;
use App\Domains\People\Performance\Data\JobDescriptionDraft;
use App\Domains\People\Performance\Enums\JobDescriptionStatus;
use App\Domains\People\Performance\Exceptions\JobDescriptionException;
use App\Domains\People\Performance\Models\JobDescription;
use App\Domains\People\Performance\Services\JobDescriptionStore;
use App\Domains\People\Settings\Models\PeopleReferenceEntry;
use App\Domains\People\Skills\Enums\RequirementProfileStatus;
use App\Domains\People\Skills\Exceptions\MissingCompanyScopeException;
use App\Domains\People\Skills\Models\RequirementProfile;
use App\Domains\People\Skills\Workflow\RequirementProfileTransitionAuthority;
use Illuminate\Support\Facades\DB;

afterEach(fn () => app(TenantContext::class)->clear());

/** @return array{tenant: int, company: int, sibling: int, hr: User, hod: User, position: int, profile: int} */
function jobDescriptionFixture(): array
{
    [$tenant, $company] = createTenantWithCompany(['name' => 'JD Tenant'], ['name' => 'JD Company', 'status' => 'active']);
    $tenantId = (int) $tenant->id;
    $companyId = (int) $company->id;
    $siblingId = (int) Company::factory()->create(['tenant_id' => $tenantId, 'status' => 'active'])->id;
    app(TenantContext::class)->set($tenantId);
    setupAuthzRoles();

    $users = [];
    foreach (['hr' => 'people_hr', 'hod' => 'people_hod'] as $key => $role) {
        $users[$key] = User::factory()->create(['company_id' => $companyId]);
        PrincipalRole::query()->create([
            'company_id' => $companyId,
            'principal_type' => PrincipalType::USER->value,
            'principal_id' => $users[$key]->id,
            'role_id' => Role::query()->whereNull('company_id')->where('code', $role)->valueOrFail('id'),
        ]);
    }

    $position = PeopleReferenceEntry::query()->create([
        'company_id' => $companyId,
        'type' => PeopleReferenceEntry::TYPE_JOB_TITLE,
        'code' => 'ENG',
        'name' => 'Engineer',
        'status' => PeopleReferenceEntry::STATUS_ACTIVE,
    ]);
    $profile = [
        'id' => 9000,
        'tenant_id' => $tenantId,
        'company_entity_id' => $companyId,
        'code' => 'engineer',
        'name' => 'Engineer',
        'version' => 3,
        'status' => RequirementProfileStatus::Published->value,
        'published_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ];
    DB::transaction(function () use ($profile): void {
        app(RequirementProfileTransitionAuthority::class)
            ->authorizeDatabaseRestore('people_connector_skill_requirement_profiles', $profile);
        DB::table('people_connector_skill_requirement_profiles')->insert($profile);
    });

    return ['tenant' => $tenantId, 'company' => $companyId, 'sibling' => $siblingId, 'hr' => $users['hr'], 'hod' => $users['hod'], 'position' => (int) $position->id, 'profile' => 9000];
}

function jobDescriptionDraft(array $fixture, int $version = 1, ?int $profileId = null): JobDescriptionDraft
{
    return new JobDescriptionDraft(
        reference: 'software-engineer',
        positionStableId: (string) $fixture['position'],
        positionVersion: 4,
        version: $version,
        effectiveFrom: '2026-09-01',
        effectiveTo: null,
        purpose: 'Build dependable products.',
        responsibilities: ['Own reliable product delivery'],
        duties: ['Review production readiness'],
        authority: 'Approve expenditure up to the departmental limit.',
        qualifications: ['Relevant engineering degree or equivalent experience'],
        competencyLinks: [[
            'requirement_profile_id' => $profileId ?? $fixture['profile'],
            'requirement_profile_version' => 3,
        ]],
    );
}

it('preserves structured content and exact competency links across published versions', function (): void {
    $fixture = jobDescriptionFixture();
    $store = app(JobDescriptionStore::class);
    $first = $store->draft($fixture['company'], jobDescriptionDraft($fixture));
    $published = $store->publish($fixture['hr'], $fixture['company'], (int) $first->id);
    $replacement = $store->draft($fixture['company'], jobDescriptionDraft($fixture, 2));

    $current = $store->supersede($fixture['hr'], $fixture['company'], (int) $published->id, (int) $replacement->id);

    expect($current->status)->toBe(JobDescriptionStatus::Published)
        ->and($current->position_version)->toBe(4)
        ->and($current->purpose)->toBe('Build dependable products.')
        ->and($current->responsibilities)->toBe(['Own reliable product delivery'])
        ->and($current->duties)->toBe(['Review production readiness'])
        ->and($current->authority)->toBe('Approve expenditure up to the departmental limit.')
        ->and($current->qualifications)->toBe(['Relevant engineering degree or equivalent experience'])
        ->and($current->competency_links)->toBe([[
            'requirement_profile_id' => $fixture['profile'],
            'requirement_profile_version' => 3,
        ]])
        ->and($published->refresh()->status)->toBe(JobDescriptionStatus::Superseded);
});

it('resolves the exact position and job-description versions effective on the requested date', function (): void {
    $fixture = jobDescriptionFixture();
    $store = app(JobDescriptionStore::class);
    $first = $store->publish(
        $fixture['hr'],
        $fixture['company'],
        (int) $store->draft($fixture['company'], jobDescriptionDraft($fixture))->id,
    );
    $futureDraft = jobDescriptionDraft($fixture, 2);
    $futureDraft = new JobDescriptionDraft(...[
        ...get_object_vars($futureDraft),
        'effectiveFrom' => '2027-01-01',
    ]);
    $future = $store->draft($fixture['company'], $futureDraft);
    $store->supersede($fixture['hr'], $fixture['company'], (int) $first->id, (int) $future->id);

    expect($store->applicable($fixture['company'], (string) $fixture['position'], 4, new DateTimeImmutable('2026-12-31'))?->id)
        ->toBe($first->id)
        ->and($store->applicable($fixture['company'], (string) $fixture['position'], 4, new DateTimeImmutable('2027-01-01'))?->id)
        ->toBe($future->id)
        ->and($store->applicable($fixture['company'], (string) $fixture['position'], 5, new DateTimeImmutable('2027-01-01')))
        ->toBeNull();
});

it('keeps published shared content immutable and grants no capability from authority prose', function (): void {
    $fixture = jobDescriptionFixture();
    $store = app(JobDescriptionStore::class);
    $description = $store->draft($fixture['company'], jobDescriptionDraft($fixture));

    expect(fn () => $store->publish($fixture['hod'], $fixture['company'], (int) $description->id))
        ->toThrow(AuthorizationDeniedException::class);

    $published = $store->publish($fixture['hr'], $fixture['company'], (int) $description->id);
    $published->authority = 'Approve all expenditure without limit.';

    expect(fn () => $published->save())
        ->toThrow(JobDescriptionException::class, 'cannot be modified')
        ->and($published->refresh()->authority)->toBe('Approve expenditure up to the departmental limit.');
});

it('refuses a missing tenant', function (): void {
    $fixture = jobDescriptionFixture();
    $store = app(JobDescriptionStore::class);
    app(TenantContext::class)->clear();

    expect(fn () => $store->draft($fixture['company'], jobDescriptionDraft($fixture)))
        ->toThrow(JobDescriptionException::class, 'tenant context');
});

it('refuses a position owned by another company', function (): void {
    $fixture = jobDescriptionFixture();
    $otherPosition = PeopleReferenceEntry::query()->create([
        'company_id' => $fixture['sibling'],
        'type' => PeopleReferenceEntry::TYPE_JOB_TITLE,
        'code' => 'OTHER',
        'name' => 'Other Engineer',
        'status' => PeopleReferenceEntry::STATUS_ACTIVE,
    ]);
    $draft = jobDescriptionDraft($fixture);
    $draft = new JobDescriptionDraft(...[
        ...get_object_vars($draft),
        'positionStableId' => (string) $otherPosition->id,
    ]);

    expect(fn () => app(JobDescriptionStore::class)->draft($fixture['company'], $draft))
        ->toThrow(JobDescriptionException::class, 'position is not active in this company');
});

it('refuses a requirement-profile version that is not published', function (): void {
    $fixture = jobDescriptionFixture();
    $draftProfile = RequirementProfile::query()->create([
        'tenant_id' => $fixture['tenant'],
        'company_entity_id' => $fixture['company'],
        'code' => 'draft-engineer',
        'name' => 'Draft Engineer',
        'version' => 1,
        'status' => RequirementProfileStatus::Draft,
    ]);

    expect(fn () => app(JobDescriptionStore::class)->draft($fixture['company'], jobDescriptionDraft($fixture, profileId: (int) $draftProfile->id)))
        ->toThrow(JobDescriptionException::class, 'published requirement-profile version');
});

it('refuses publication without HR capability', function (): void {
    $fixture = jobDescriptionFixture();
    $store = app(JobDescriptionStore::class);
    $draft = $store->draft($fixture['company'], jobDescriptionDraft($fixture));

    expect(fn () => $store->publish($fixture['hod'], $fixture['company'], (int) $draft->id))
        ->toThrow(AuthorizationDeniedException::class);
});

it('refuses publication of a non-draft', function (): void {
    $fixture = jobDescriptionFixture();
    $store = app(JobDescriptionStore::class);
    $draft = $store->draft($fixture['company'], jobDescriptionDraft($fixture));
    $store->publish($fixture['hr'], $fixture['company'], (int) $draft->id);
    expect(fn () => $store->publish($fixture['hr'], $fixture['company'], (int) $draft->id))
        ->toThrow(JobDescriptionException::class, 'Only a draft');
});

it('requires every model query to pin tenant and company', function (): void {
    $fixture = jobDescriptionFixture();
    app(JobDescriptionStore::class)->draft($fixture['company'], jobDescriptionDraft($fixture));

    expect(fn () => JobDescription::query()->forTenant($fixture['tenant'])->get())
        ->toThrow(MissingCompanyScopeException::class)
        ->and(JobDescription::query()->forCompany($fixture['tenant'], $fixture['company'])->count())->toBe(1);
});

it('refuses publication for another company even when the actor can publish job descriptions', function (): void {
    $fixture = jobDescriptionFixture();
    $draft = app(JobDescriptionStore::class)->draft($fixture['company'], jobDescriptionDraft($fixture));

    expect(fn () => app(JobDescriptionStore::class)->publish(
        $fixture['hr'],
        $fixture['sibling'],
        (int) $draft->id,
    ))->toThrow(JobDescriptionException::class, 'may not publish job descriptions for this company');
});

it('refuses supersession without the job-description capability', function (): void {
    $fixture = jobDescriptionFixture();
    $store = app(JobDescriptionStore::class);
    $published = $store->publish($fixture['hr'], $fixture['company'], (int) $store->draft($fixture['company'], jobDescriptionDraft($fixture))->id);
    $replacement = $store->draft($fixture['company'], jobDescriptionDraft($fixture, 2));

    expect(fn () => $store->supersede(
        $fixture['hod'],
        $fixture['company'],
        (int) $published->id,
        (int) $replacement->id,
    ))->toThrow(AuthorizationDeniedException::class);
});

it('refuses applicable-version lookup without a tenant context', function (): void {
    $fixture = jobDescriptionFixture();
    $store = app(JobDescriptionStore::class);
    $store->publish($fixture['hr'], $fixture['company'], (int) $store->draft($fixture['company'], jobDescriptionDraft($fixture))->id);
    app(TenantContext::class)->clear();

    expect(fn () => $store->applicable(
        $fixture['company'],
        (string) $fixture['position'],
        4,
        new DateTimeImmutable('2026-09-01'),
    ))->toThrow(JobDescriptionException::class, 'tenant context');
});
