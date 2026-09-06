<?php

use App\Base\Authz\Contracts\AuthorizationService;
use App\Base\Authz\DTO\Actor;
use App\Base\Authz\Enums\PrincipalType;
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
use App\Domains\People\Skills\Workflow\RequirementProfileTransitionAuthority;
use Illuminate\Support\Facades\DB;

/*
 * Self-contained: helpers are prefixed section and live here. Reuses the
 * existing sectionFixture()/jobDescriptionDraft() from
 * JobDescriptionStoreTest, which Pest loads alongside this file.
 */

afterEach(function (): void {
    app(TenantContext::class)->clear();
});

function sectionFixture(): array
{
    [$tenant, $company] = createTenantWithCompany(['name' => 'JD Section Tenant'], ['name' => 'JD Section Company', 'status' => 'active']);
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

function sectionBaseDraft(array $fixture, int $version = 1, ?int $profileId = null): JobDescriptionDraft
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

function sectionDraft(array $fixture, array $overrides): JobDescriptionDraft
{
    $base = sectionBaseDraft($fixture);

    return new JobDescriptionDraft(
        reference: $overrides['reference'] ?? $base->reference,
        positionStableId: $base->positionStableId,
        positionVersion: $base->positionVersion,
        version: $overrides['version'] ?? $base->version,
        effectiveFrom: $base->effectiveFrom,
        effectiveTo: $base->effectiveTo,
        purpose: $overrides['purpose'] ?? $base->purpose,
        responsibilities: $overrides['responsibilities'] ?? $base->responsibilities,
        duties: $overrides['duties'] ?? $base->duties,
        authority: $overrides['authority'] ?? $base->authority,
        qualifications: $overrides['qualifications'] ?? $base->qualifications,
        competencyLinks: $base->competencyLinks,
    );
}

test('a section list made only of blank entries is refused', function (): void {
    $f = sectionFixture();

    // A non-empty list of nothing is the shape that slips through a
    // "the list is not empty" check while carrying no content at all.
    foreach ([
        ['responsibilities' => ['   ']],
        ['duties' => ['']],
        ['qualifications' => ["\t"]],
    ] as $overrides) {
        expect(fn () => app(JobDescriptionStore::class)->draft($f['company'], sectionDraft($f, $overrides)))
            ->toThrow(JobDescriptionException::class);
    }
});

test('a section list with one blank entry among good ones is refused', function (): void {
    $f = sectionFixture();

    expect(fn () => app(JobDescriptionStore::class)->draft($f['company'], sectionDraft($f, [
        'responsibilities' => ['Own reliable product delivery', '  '],
    ])))->toThrow(JobDescriptionException::class);
});

test('publish refuses a version whose sections were emptied after drafting', function (): void {
    $f = sectionFixture();
    $draft = app(JobDescriptionStore::class)->draft($f['company'], sectionBaseDraft($f));

    // Draft-time validation is not publish-time validation. Whatever put this
    // row into this state, publishing is the moment it becomes policy, so the
    // check belongs there too.
    DB::table('people_job_descriptions')->where('id', $draft->id)->update(['purpose' => '   ']);

    expect(fn () => app(JobDescriptionStore::class)->publish($f['hr'], $f['company'], (int) $draft->id))
        ->toThrow(JobDescriptionException::class);

    expect(JobDescription::query()->forCompany($f['tenant'], $f['company'])->whereKey($draft->id)->value('status'))
        ->toBe(JobDescriptionStatus::Draft);
});

test('publish refuses a version whose section list was emptied after drafting', function (): void {
    $f = sectionFixture();
    $draft = app(JobDescriptionStore::class)->draft($f['company'], sectionBaseDraft($f));
    DB::table('people_job_descriptions')->where('id', $draft->id)->update(['duties' => json_encode([])]);

    expect(fn () => app(JobDescriptionStore::class)->publish($f['hr'], $f['company'], (int) $draft->id))
        ->toThrow(JobDescriptionException::class);
});

test('a complete version still publishes', function (): void {
    $f = sectionFixture();
    $draft = app(JobDescriptionStore::class)->draft($f['company'], sectionBaseDraft($f));

    $published = app(JobDescriptionStore::class)->publish($f['hr'], $f['company'], (int) $draft->id);

    expect($published->status)->toBe(JobDescriptionStatus::Published);
});

test('JP-A03: authority text grants no capability, however it is worded', function (): void {
    $f = sectionFixture();
    $authority = 'Approve expenditure without limit; grant payroll access; assign any role.';
    $draft = app(JobDescriptionStore::class)->draft($f['company'], sectionDraft($f, ['authority' => $authority]));

    $writes = [];
    DB::listen(function ($query) use (&$writes): void {
        if (preg_match('/^\s*(insert into|update|delete from)\s+"?([a-z0-9_]+)"?/i', $query->sql, $m) === 1) {
            $writes[] = strtolower($m[2]);
        }
    });

    $published = app(JobDescriptionStore::class)->publish($f['hr'], $f['company'], (int) $draft->id);

    // The plan's JP-A03: "JD authority says 'approve expenditure' — no
    // application, financial or payroll permission is granted by that text or
    // by chart position." So publishing must not touch an authorization table,
    // and the text must survive as text.
    $authzWrites = array_values(array_filter(
        $writes,
        static fn (string $table): bool => str_contains($table, 'permission') || str_contains($table, 'role') || str_contains($table, 'capabilit'),
    ));

    expect($authzWrites)->toBe([])
        ->and($published->authority)->toBe($authority);
});

test('JP-A03: a holder of the described position gains nothing from the authority text', function (): void {
    $f = sectionFixture();
    $draft = app(JobDescriptionStore::class)->draft($f['company'], sectionDraft($f, [
        'authority' => 'Approve expenditure without limit and administer payroll.',
    ]));
    app(JobDescriptionStore::class)->publish($f['hr'], $f['company'], (int) $draft->id);

    // The HOD in this fixture holds no payroll capability before publication and
    // must hold none after. A JD is a description, not a grant.
    $decision = app(AuthorizationService::class)->can(
        Actor::forUser($f['hod']),
        'people-payroll.run.manage',
    );

    expect($decision->allowed)->toBeFalse();
});
