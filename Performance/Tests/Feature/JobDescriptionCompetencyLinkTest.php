<?php

use App\Base\Authz\Enums\PrincipalType;
use App\Base\Authz\Models\PrincipalRole;
use App\Base\Authz\Models\Role;
use App\Base\Tenancy\Contracts\TenantContext;
use App\Core\Company\Models\Company;
use App\Core\User\Models\User;
use App\Domains\People\Performance\Data\JobDescriptionDraft;
use App\Domains\People\Performance\Exceptions\JobDescriptionException;
use App\Domains\People\Performance\Services\JobDescriptionStore;
use App\Domains\People\Settings\Models\PeopleReferenceEntry;
use App\Domains\People\Skills\Enums\RequirementProfileStatus;
use App\Domains\People\Skills\Models\RequirementProfile;
use App\Domains\People\Skills\Workflow\RequirementProfileTransitionAuthority;
use Illuminate\Support\Facades\DB;

/*
 * Self-contained: helpers are prefixed link and live here.
 *
 * A job description points at a requirement profile version. It never says in
 * its own words what the competency is, because two descriptions of the same
 * requirement drift and only one of them is governed.
 */

afterEach(function (): void {
    app(TenantContext::class)->clear();
});

function linkBaseFixture(): array
{
    [$tenant, $company] = createTenantWithCompany(['name' => 'JD Link Tenant'], ['name' => 'JD Link Company', 'status' => 'active']);
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

function linkBaseDraft(array $fixture, int $version = 1, ?int $profileId = null): JobDescriptionDraft
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

function linkProfileRow(int $tenantId, int $companyId, array $attributes): int
{
    $row = array_replace([
        'tenant_id' => $tenantId,
        'company_entity_id' => $companyId,
        'code' => 'engineer',
        'name' => 'Engineer',
        'version' => 3,
        'status' => RequirementProfileStatus::Published->value,
        'published_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ], $attributes);

    DB::transaction(function () use ($row): void {
        app(RequirementProfileTransitionAuthority::class)
            ->authorizeDatabaseRestore('people_connector_skill_requirement_profiles', $row);
        DB::table('people_connector_skill_requirement_profiles')->insert($row);
    });

    return (int) $row['id'];
}

/** @return array{tenant: int, company: int, published: int, draft: int, retired: int} */
function linkFixture(): array
{
    $f = linkBaseFixture();

    return [
        'tenant' => $f['tenant'],
        'company' => $f['company'],
        'hr' => $f['hr'],
        'position' => $f['position'],
        'profile' => $f['profile'],
        'published' => $f['profile'],
        'draft' => linkProfileRow($f['tenant'], $f['company'], [
            'id' => 9101, 'code' => 'draft-profile', 'version' => 1,
            'status' => RequirementProfileStatus::Draft->value, 'published_at' => null,
        ]),
        'retired' => linkProfileRow($f['tenant'], $f['company'], [
            'id' => 9102, 'code' => 'retired-profile', 'version' => 1,
            'status' => RequirementProfileStatus::Retired->value, 'retired_at' => now(),
        ]),
    ];
}

function linkDraft(array $f, array $links): JobDescriptionDraft
{
    $base = linkBaseDraft($f);

    return new JobDescriptionDraft(
        reference: $base->reference,
        positionStableId: $base->positionStableId,
        positionVersion: $base->positionVersion,
        version: $base->version,
        effectiveFrom: $base->effectiveFrom,
        effectiveTo: $base->effectiveTo,
        purpose: $base->purpose,
        responsibilities: $base->responsibilities,
        duties: $base->duties,
        authority: $base->authority,
        qualifications: $base->qualifications,
        competencyLinks: $links,
    );
}

test('a competency link naming a draft requirement version is refused', function (): void {
    $f = linkFixture();

    expect(fn () => app(JobDescriptionStore::class)->draft($f['company'], linkDraft($f, [
        ['requirement_profile_id' => $f['draft'], 'requirement_profile_version' => 1],
    ])))->toThrow(JobDescriptionException::class);
});

test('a competency link naming a retired requirement version is refused', function (): void {
    $f = linkFixture();

    // A retired version stays readable for the assessments taken against it,
    // but a job description published now must not require it.
    expect(fn () => app(JobDescriptionStore::class)->draft($f['company'], linkDraft($f, [
        ['requirement_profile_id' => $f['retired'], 'requirement_profile_version' => 1],
    ])))->toThrow(JobDescriptionException::class);
});

test('a competency link carrying its own prose is refused', function (): void {
    $f = linkFixture();

    // The title of this lane: never prose. A link that also carries text is two
    // descriptions of one requirement, and only the profile's is governed — so
    // the copy is free to drift and nobody would know which one applied.
    expect(fn () => app(JobDescriptionStore::class)->draft($f['company'], linkDraft($f, [
        [
            'requirement_profile_id' => $f['published'],
            'requirement_profile_version' => 3,
            'description' => 'Must be able to weld to AWS D1.1.',
        ],
    ])))->toThrow(JobDescriptionException::class);
});

test('a competency link missing either identifier is refused', function (): void {
    $f = linkFixture();
    $store = app(JobDescriptionStore::class);

    foreach ([
        ['requirement_profile_id' => $f['published']],
        ['requirement_profile_version' => 3],
        [],
    ] as $link) {
        expect(fn () => $store->draft($f['company'], linkDraft($f, [$link])))
            ->toThrow(JobDescriptionException::class);
    }
});

test('the stored link is the reference and nothing else', function (): void {
    $f = linkFixture();

    $jd = app(JobDescriptionStore::class)->draft($f['company'], linkDraft($f, [
        ['requirement_profile_id' => $f['published'], 'requirement_profile_version' => 3],
    ]));

    // The read exposes the link, not a copy of what the profile says. Anyone
    // wanting the competency text resolves the version and reads it there,
    // where it is governed.
    expect($jd->competency_links)->toBe([
        ['requirement_profile_id' => $f['published'], 'requirement_profile_version' => 3],
    ])
        ->and(RequirementProfile::query()->forCompany($f['tenant'], $f['company'])
            ->whereKey($f['published'])->value('status'))->toBe(RequirementProfileStatus::Published);
});
