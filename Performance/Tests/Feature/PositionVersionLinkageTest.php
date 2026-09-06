<?php

use App\Base\Authz\Enums\PrincipalType;
use App\Base\Authz\Models\PrincipalRole;
use App\Base\Authz\Models\Role;
use App\Base\Tenancy\Contracts\TenantContext;
use App\Core\Company\Models\Company;
use App\Core\Employee\Models\Employee;
use App\Core\User\Models\User;
use App\Domains\People\Organisation\Data\PositionAssignmentDraft;
use App\Domains\People\Organisation\Data\PositionVersionDraft;
use App\Domains\People\Organisation\Enums\PositionAssignmentType;
use App\Domains\People\Organisation\Exceptions\InvalidPositionDirectoryException;
use App\Domains\People\Organisation\Services\PositionDirectory;
use App\Domains\People\Performance\Data\JobDescriptionDraft;
use App\Domains\People\Performance\Exceptions\JobDescriptionException;
use App\Domains\People\Performance\Services\JobDescriptionStore;
use App\Domains\People\Settings\Models\PeopleReferenceEntry;
use App\Domains\People\Skills\Enums\RequirementProfileStatus;
use App\Domains\People\Skills\Workflow\RequirementProfileTransitionAuthority;
use Illuminate\Support\Facades\DB;

afterEach(fn () => app(TenantContext::class)->clear());

/**
 * JP-A02: transfer, vacancy, acting and concurrent appointments must each yield
 * an unambiguous applicable description, and no shared job description may be
 * quietly edited for one employee.
 *
 * Self-contained: Pest does not load helpers from sibling test files.
 *
 * @return array<string, mixed>
 */
function positionFixture(): array
{
    [$tenant, $company] = createTenantWithCompany(['name' => 'Position Tenant'], ['name' => 'Position Company', 'status' => 'active']);
    $tenantId = (int) $tenant->id;
    $companyId = (int) $company->id;
    $siblingId = (int) Company::factory()->create(['tenant_id' => $tenantId, 'status' => 'active'])->id;
    app(TenantContext::class)->set($tenantId);
    setupAuthzRoles();

    $hr = User::factory()->create(['company_id' => $companyId]);
    PrincipalRole::query()->create([
        'company_id' => $companyId, 'principal_type' => PrincipalType::USER->value,
        'principal_id' => $hr->id,
        'role_id' => Role::query()->whereNull('company_id')->where('code', 'people_hr')->valueOrFail('id'),
    ]);

    $positions = [];
    foreach ([['ENG', 'Engineer'], ['LEAD', 'Team Lead']] as [$code, $name]) {
        $positions[$code] = (int) PeopleReferenceEntry::query()->create([
            'company_id' => $companyId, 'type' => PeopleReferenceEntry::TYPE_JOB_TITLE,
            'code' => $code, 'name' => $name, 'status' => PeopleReferenceEntry::STATUS_ACTIVE,
        ])->id;
    }
    $employee = Employee::factory()->create([
        'company_id' => $companyId, 'full_name' => 'Transferring Employee',
        'status' => 'active', 'employee_type' => 'full_time',
    ]);

    $profile = [
        'id' => 9100, 'tenant_id' => $tenantId, 'company_entity_id' => $companyId,
        'code' => 'engineer', 'name' => 'Engineer', 'version' => 3,
        'status' => RequirementProfileStatus::Published->value,
        'published_at' => now(), 'created_at' => now(), 'updated_at' => now(),
    ];
    DB::transaction(function () use ($profile): void {
        app(RequirementProfileTransitionAuthority::class)
            ->authorizeDatabaseRestore('people_connector_skill_requirement_profiles', $profile);
        DB::table('people_connector_skill_requirement_profiles')->insert($profile);
    });

    return [
        'tenant' => $tenantId, 'company' => $companyId, 'sibling' => $siblingId,
        'hr' => $hr, 'positions' => $positions, 'employee' => (int) $employee->id, 'profile' => 9100,
    ];
}

function positionVersionDraft(array $f, string $code, int $version, string $from, ?string $to = null): PositionVersionDraft
{
    return new PositionVersionDraft(
        positionStableId: (string) $f['positions'][$code],
        version: $version,
        title: $code.' v'.$version,
        effectiveFrom: new DateTimeImmutable($from),
        effectiveTo: $to === null ? null : new DateTimeImmutable($to),
    );
}

function positionJobDescription(array $f, string $code, int $positionVersion, int $version, string $from, ?string $to = null): int
{
    $store = app(JobDescriptionStore::class);
    $draft = $store->draft($f['company'], new JobDescriptionDraft(
        reference: strtolower($code).'-v'.$positionVersion,
        positionStableId: (string) $f['positions'][$code],
        positionVersion: $positionVersion,
        version: $version,
        effectiveFrom: $from,
        effectiveTo: $to,
        purpose: 'Deliver the '.$code.' outcomes.',
        responsibilities: ['Own the '.$code.' outcomes'],
        duties: ['Report on the '.$code.' outcomes'],
        authority: 'Approve within the delegated limit.',
        qualifications: ['Relevant experience'],
        competencyLinks: [['requirement_profile_id' => $f['profile'], 'requirement_profile_version' => 3]],
    ));

    return (int) $store->publish($f['hr'], $f['company'], (int) $draft->id)->id;
}

test('a position version records its own effective interval and resolves at a date', function (): void {
    $f = positionFixture();
    $directory = app(PositionDirectory::class);
    $directory->recordVersion($f['company'], positionVersionDraft($f, 'ENG', 1, '2026-01-01', '2026-06-30'));
    $directory->recordVersion($f['company'], positionVersionDraft($f, 'ENG', 2, '2026-07-01'));

    expect($directory->versionAt($f['company'], (string) $f['positions']['ENG'], new DateTimeImmutable('2026-03-01'))?->version)->toBe(1)
        ->and($directory->versionAt($f['company'], (string) $f['positions']['ENG'], new DateTimeImmutable('2026-09-01'))?->version)->toBe(2)
        ->and($directory->versionAt($f['company'], (string) $f['positions']['ENG'], new DateTimeImmutable('2025-12-31')))->toBeNull();
});

test('two position versions of one position may not claim the same day', function (): void {
    $f = positionFixture();
    $directory = app(PositionDirectory::class);
    $directory->recordVersion($f['company'], positionVersionDraft($f, 'ENG', 1, '2026-01-01', '2026-06-30'));

    expect(fn () => $directory->recordVersion($f['company'], positionVersionDraft($f, 'ENG', 2, '2026-06-30')))
        ->toThrow(InvalidPositionDirectoryException::class, 'overlaps');
});

test('a job description cannot bind to a position version the directory does not have', function (): void {
    $f = positionFixture();
    app(PositionDirectory::class)->recordVersion($f['company'], positionVersionDraft($f, 'ENG', 1, '2026-01-01'));

    expect(fn () => positionJobDescription($f, 'ENG', 7, 1, '2026-01-01'))
        ->toThrow(JobDescriptionException::class, 'position version');
});

test('a transfer resolves the old description before the move and the new one after', function (): void {
    $f = positionFixture();
    $directory = app(PositionDirectory::class);
    $directory->recordVersion($f['company'], positionVersionDraft($f, 'ENG', 1, '2026-01-01'));
    $directory->recordVersion($f['company'], positionVersionDraft($f, 'LEAD', 1, '2026-01-01'));
    $engineerJd = positionJobDescription($f, 'ENG', 1, 1, '2026-01-01');
    $leadJd = positionJobDescription($f, 'LEAD', 1, 1, '2026-01-01');

    $directory->assign($f['company'], new PositionAssignmentDraft(
        positionStableId: (string) $f['positions']['ENG'], employeeEntityId: $f['employee'],
        type: PositionAssignmentType::Substantive,
        effectiveFrom: new DateTimeImmutable('2026-01-01'), effectiveTo: new DateTimeImmutable('2026-06-30'),
    ));
    $directory->assign($f['company'], new PositionAssignmentDraft(
        positionStableId: (string) $f['positions']['LEAD'], employeeEntityId: $f['employee'],
        type: PositionAssignmentType::Substantive,
        effectiveFrom: new DateTimeImmutable('2026-07-01'), effectiveTo: null,
    ));

    $store = app(JobDescriptionStore::class);
    $before = $store->applicableForEmployee($f['company'], $f['employee'], new DateTimeImmutable('2026-03-01'));
    $after = $store->applicableForEmployee($f['company'], $f['employee'], new DateTimeImmutable('2026-08-01'));

    expect($before)->toHaveCount(1)
        ->and((int) $before[0]['job_description']->id)->toBe($engineerJd)
        ->and($after)->toHaveCount(1)
        ->and((int) $after[0]['job_description']->id)->toBe($leadJd);
});

test('an acting appointment alongside a substantive one resolves both, each named', function (): void {
    $f = positionFixture();
    $directory = app(PositionDirectory::class);
    $directory->recordVersion($f['company'], positionVersionDraft($f, 'ENG', 1, '2026-01-01'));
    $directory->recordVersion($f['company'], positionVersionDraft($f, 'LEAD', 1, '2026-01-01'));
    $engineerJd = positionJobDescription($f, 'ENG', 1, 1, '2026-01-01');
    $leadJd = positionJobDescription($f, 'LEAD', 1, 1, '2026-01-01');

    $directory->assign($f['company'], new PositionAssignmentDraft(
        positionStableId: (string) $f['positions']['ENG'], employeeEntityId: $f['employee'],
        type: PositionAssignmentType::Substantive,
        effectiveFrom: new DateTimeImmutable('2026-01-01'), effectiveTo: null,
    ));
    $directory->assign($f['company'], new PositionAssignmentDraft(
        positionStableId: (string) $f['positions']['LEAD'], employeeEntityId: $f['employee'],
        type: PositionAssignmentType::Acting,
        effectiveFrom: new DateTimeImmutable('2026-05-01'), effectiveTo: new DateTimeImmutable('2026-05-31'),
    ));

    $rows = app(JobDescriptionStore::class)
        ->applicableForEmployee($f['company'], $f['employee'], new DateTimeImmutable('2026-05-15'));
    $byType = collect($rows)->keyBy(fn (array $row): string => $row['assignment_type']->value);

    expect($rows)->toHaveCount(2)
        ->and((int) $byType[PositionAssignmentType::Substantive->value]['job_description']->id)->toBe($engineerJd)
        ->and((int) $byType[PositionAssignmentType::Acting->value]['job_description']->id)->toBe($leadJd);
});

test('a second substantive holder of one position over the same days is refused', function (): void {
    $f = positionFixture();
    $directory = app(PositionDirectory::class);
    $directory->recordVersion($f['company'], positionVersionDraft($f, 'ENG', 1, '2026-01-01'));
    $other = (int) Employee::factory()->create([
        'company_id' => $f['company'], 'full_name' => 'Second Holder',
        'status' => 'active', 'employee_type' => 'full_time',
    ])->id;
    $directory->assign($f['company'], new PositionAssignmentDraft(
        positionStableId: (string) $f['positions']['ENG'], employeeEntityId: $f['employee'],
        type: PositionAssignmentType::Substantive,
        effectiveFrom: new DateTimeImmutable('2026-01-01'), effectiveTo: null,
    ));

    expect(fn () => $directory->assign($f['company'], new PositionAssignmentDraft(
        positionStableId: (string) $f['positions']['ENG'], employeeEntityId: $other,
        type: PositionAssignmentType::Substantive,
        effectiveFrom: new DateTimeImmutable('2026-03-01'), effectiveTo: null,
    )))->toThrow(InvalidPositionDirectoryException::class, 'substantive');
});

test('an acting appointment may overlap the substantive holder of the same position', function (): void {
    $f = positionFixture();
    $directory = app(PositionDirectory::class);
    $directory->recordVersion($f['company'], positionVersionDraft($f, 'ENG', 1, '2026-01-01'));
    $cover = (int) Employee::factory()->create([
        'company_id' => $f['company'], 'full_name' => 'Cover',
        'status' => 'active', 'employee_type' => 'full_time',
    ])->id;
    $directory->assign($f['company'], new PositionAssignmentDraft(
        positionStableId: (string) $f['positions']['ENG'], employeeEntityId: $f['employee'],
        type: PositionAssignmentType::Substantive,
        effectiveFrom: new DateTimeImmutable('2026-01-01'), effectiveTo: null,
    ));

    $acting = $directory->assign($f['company'], new PositionAssignmentDraft(
        positionStableId: (string) $f['positions']['ENG'], employeeEntityId: $cover,
        type: PositionAssignmentType::Acting,
        effectiveFrom: new DateTimeImmutable('2026-04-01'), effectiveTo: new DateTimeImmutable('2026-04-30'),
    ));

    expect($acting->type)->toBe(PositionAssignmentType::Acting);
});

test('a vacant position keeps its job description', function (): void {
    $f = positionFixture();
    $directory = app(PositionDirectory::class);
    $directory->recordVersion($f['company'], positionVersionDraft($f, 'ENG', 1, '2026-01-01'));
    $engineerJd = positionJobDescription($f, 'ENG', 1, 1, '2026-01-01');

    // Nobody has ever held it. The description is the position's, not the
    // holder's, so it must still resolve.
    $store = app(JobDescriptionStore::class);
    $holders = $directory->assignmentsAt($f['company'], (string) $f['positions']['ENG'], new DateTimeImmutable('2026-05-01'));
    $applicable = $store->applicable($f['company'], (string) $f['positions']['ENG'], 1, new DateTimeImmutable('2026-05-01'));

    expect($holders)->toBe([])
        ->and($applicable)->not->toBeNull()
        ->and((int) $applicable->id)->toBe($engineerJd);
});

test('a description published for one position version does not follow the next one', function (): void {
    $f = positionFixture();
    $directory = app(PositionDirectory::class);
    $directory->recordVersion($f['company'], positionVersionDraft($f, 'ENG', 1, '2026-01-01', '2026-06-30'));
    $directory->recordVersion($f['company'], positionVersionDraft($f, 'ENG', 2, '2026-07-01'));
    positionJobDescription($f, 'ENG', 1, 1, '2026-01-01');

    $store = app(JobDescriptionStore::class);

    expect($store->applicable($f['company'], (string) $f['positions']['ENG'], 1, new DateTimeImmutable('2026-03-01')))->not->toBeNull()
        ->and($store->applicable($f['company'], (string) $f['positions']['ENG'], 2, new DateTimeImmutable('2026-09-01')))->toBeNull();
});

test('the directory refuses a position version for another company', function (): void {
    $f = positionFixture();

    expect(fn () => app(PositionDirectory::class)
        ->recordVersion($f['sibling'], positionVersionDraft($f, 'ENG', 1, '2026-01-01')))
        ->toThrow(InvalidPositionDirectoryException::class);
});

test('an interval that ends before it starts is refused for both versions and assignments', function (): void {
    $f = positionFixture();
    $directory = app(PositionDirectory::class);

    // Nothing in the schema stops this, and a backwards interval is worse than
    // an error: it stores a row that no as-of date can ever match.
    expect(fn () => $directory->recordVersion($f['company'], positionVersionDraft($f, 'ENG', 1, '2026-06-01', '2026-01-01')))
        ->toThrow(InvalidPositionDirectoryException::class, 'cannot end before it starts');

    $directory->recordVersion($f['company'], positionVersionDraft($f, 'ENG', 1, '2026-01-01'));

    expect(fn () => $directory->assign($f['company'], new PositionAssignmentDraft(
        positionStableId: (string) $f['positions']['ENG'], employeeEntityId: $f['employee'],
        type: PositionAssignmentType::Substantive,
        effectiveFrom: new DateTimeImmutable('2026-06-01'), effectiveTo: new DateTimeImmutable('2026-01-01'),
    )))->toThrow(InvalidPositionDirectoryException::class, 'cannot end before it starts');
});
