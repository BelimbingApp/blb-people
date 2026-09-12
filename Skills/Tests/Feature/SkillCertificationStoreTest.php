<?php

use App\Base\Authz\Exceptions\AuthorizationDeniedException;
use App\Base\Tenancy\Contracts\TenantContext;
use App\Core\Company\Models\Company;
use App\Core\Employee\Models\Employee;
use App\Core\User\Models\User;
use App\Domains\People\Provider\Enums\WorkforceResourceType;
use App\Domains\People\Skills\Data\SkillCertificationDraft;
use App\Domains\People\Skills\Data\SkillDraft;
use App\Domains\People\Skills\Enums\AssessmentCycle;
use App\Domains\People\Skills\Enums\AssessmentMethod;
use App\Domains\People\Skills\Enums\AssessmentResultBand;
use App\Domains\People\Skills\Enums\AssessmentStatus;
use App\Domains\People\Skills\Enums\HodVerification;
use App\Domains\People\Skills\Enums\RequirementCriticality;
use App\Domains\People\Skills\Exceptions\InvalidSkillCertificationException;
use App\Domains\People\Skills\Models\EmployeeSkillScore;
use App\Domains\People\Skills\Models\SkillAssessment;
use App\Domains\People\Skills\Models\SkillCertification;
use App\Domains\People\Skills\Models\SkillCertificationSkill;
use App\Domains\People\Skills\Services\AssessmentWorkflowContext;
use App\Domains\People\Skills\Services\CriticalSkillBackupCoverage;
use App\Domains\People\Skills\Services\SkillAudience;
use App\Domains\People\Skills\Services\SkillCatalogStore;
use App\Domains\People\Skills\Services\SkillCertificationStore;
use App\Domains\People\Skills\Tests\Support\NativeWorkforceFixture;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Provoke a write the database itself must refuse, inside its own savepoint.
 *
 * Two things have to be true here and only one of them is visible. The write
 * must throw, and it must throw because *this* write was refused -- not because
 * an earlier refusal in the same test already poisoned the connection.
 * PostgreSQL aborts the whole transaction on the first constraint violation, so
 * every later statement fails with SQLSTATE 25P02, which is itself a
 * QueryException. A bare toThrow(QueryException::class) therefore goes green
 * without the guard under test ever running.
 *
 * That is not hypothetical. On this branch the mirror's server log showed
 * `pcs_skill_certification_skill_immutable` never firing, while the assertion
 * that exists to prove it passed anyway; the delete had received 25P02 from the
 * preceding refused update. SQLite has no such abort, so the same suite was
 * honest on one required driver and vacuous on the other.
 *
 * The nested DB::transaction() is a SAVEPOINT: rolling back to it leaves the
 * outer RefreshDatabase transaction usable, so the next refused write and any
 * trailing count still mean something. Translating 25P02 into a different
 * exception class is what keeps the vacuum from ever being green again -- a
 * savepoint removed later fails loudly instead of silently proving nothing.
 *
 * Helper names are global across the composed Pest suite, hence the prefix.
 */
function skillCertificationRefusedWrite(Closure $write): Closure
{
    return static function () use ($write): void {
        try {
            DB::transaction($write);
        } catch (QueryException $e) {
            if ($e->getCode() === '25P02') {
                throw new RuntimeException(
                    'this write never reached its guard: the transaction was already aborted by an earlier statement'
                );
            }

            throw $e;
        }
    };
}

afterEach(function (): void {
    app(TenantContext::class)->clear();
});

/** @return array{tenantId: int, company: Company, employee: Employee, otherEmployee: Employee, skillId: int, actor: User} */
function certificationFixture(): array
{
    $tenant = createTenant(['name' => 'Certification Tenant']);
    $tenantId = (int) $tenant->id;
    app(TenantContext::class)->set($tenantId);

    $company = NativeWorkforceFixture::create($tenantId, WorkforceResourceType::Company);
    $employee = NativeWorkforceFixture::create($tenantId, WorkforceResourceType::Employee, (int) $company->id);
    $otherEmployee = NativeWorkforceFixture::create($tenantId, WorkforceResourceType::Employee, (int) $company->id);
    $category = app(SkillCatalogStore::class)->defineCategory((int) $company->id, 'safety', 'Safety');
    $skill = app(SkillCatalogStore::class)->defineSkill($company->id, new SkillDraft(
        code: 'confined.space',
        name: 'Confined space entry',
        definition: 'Works safely in a confined space.',
        categoryId: (int) $category->id,
        defaultAssessmentMethod: AssessmentMethod::DirectObservation,
    ));
    $actor = User::factory()->create(['company_id' => $company->id]);

    return compact('tenantId', 'company', 'employee', 'otherEmployee', 'skill', 'actor') + ['skillId' => (int) $skill->id];
}

function allowCertificationManagement(): void
{
    app()->instance(SkillAudience::class, new class extends SkillAudience
    {
        public function __construct() {}

        public function authorizeCertificationManagement(User $user, int $companyEntityId, int $employeeEntityId): void {}
    });
}

function certificationDraft(array $fixture, array $overrides = []): SkillCertificationDraft
{
    return new SkillCertificationDraft(...array_merge([
        'employeeEntityId' => (int) $fixture['employee']->id,
        'issuer' => 'National Safety Board',
        'externalReference' => 'CERT-001',
        'issuedOn' => new DateTimeImmutable('2026-01-01'),
        'expiresOn' => new DateTimeImmutable('2027-01-01'),
        'evidenceLink' => 'https://evidence.example.test/cert-001',
        'skillIds' => [$fixture['skillId']],
    ], $overrides));
}

function certificationScore(array $fixture, Employee $employee, int $current, ?string $validUntil = null): EmployeeSkillScore
{
    $assessment = AssessmentWorkflowContext::runStoreMutation(static fn (): SkillAssessment => SkillAssessment::query()->create([
        'tenant_id' => $fixture['tenantId'],
        'company_entity_id' => $fixture['company']->id,
        'employee_entity_id' => $employee->id,
        'skill_id' => $fixture['skillId'],
        'requirement_reference' => 'fixture.safety',
        'requirement_version' => 1,
        'required_level' => 3,
        'criticality' => RequirementCriticality::Critical,
        'weight_percent' => 100,
        'mandatory_gate' => true,
        'assessed_level' => $current,
        'gap' => max(3 - $current, 0),
        'weighted_gap' => max(3 - $current, 0) * 100,
        'priority_score' => max(3 - $current, 0) * 300,
        'result_band' => AssessmentResultBand::fromGap(max(3 - $current, 0), $current, 3),
        'method' => AssessmentMethod::DirectObservation,
        'cycle' => AssessmentCycle::Annual,
        'status' => AssessmentStatus::Draft,
        'evidence' => 'Observed task.',
        'assessed_at' => now()->subDay(),
        'hod_verification' => HodVerification::Pending,
    ]));

    return EmployeeSkillScore::query()->forCompany($fixture['tenantId'], $fixture['company']->id)->create([
        'tenant_id' => $fixture['tenantId'],
        'company_entity_id' => $fixture['company']->id,
        'employee_entity_id' => $employee->id,
        'skill_id' => $fixture['skillId'],
        'source_assessment_id' => $assessment->id,
        'requirement_reference' => 'fixture.safety',
        'requirement_version' => 1,
        'required_level' => 3,
        'current_level' => $current,
        'gap' => max(3 - $current, 0),
        'mandatory_gate' => true,
        'criticality' => RequirementCriticality::Critical,
        'assessed_at' => now()->subDay(),
        'valid_until' => $validUntil,
    ]);
}

test('a certification records its issuer, validity, evidence and skill mappings', function (): void {
    $fixture = certificationFixture();
    allowCertificationManagement();

    $certification = app(SkillCertificationStore::class)->record(
        $fixture['actor'],
        $fixture['company']->id,
        certificationDraft($fixture),
    );

    expect($certification->issuer)->toBe('National Safety Board')
        ->and($certification->external_reference)->toBe('CERT-001')
        ->and($certification->issued_on->toDateString())->toBe('2026-01-01')
        ->and($certification->expires_on->toDateString())->toBe('2027-01-01')
        ->and($certification->evidence_link)->toBe('https://evidence.example.test/cert-001')
        ->and(SkillCertificationSkill::query()
            ->forCompany($fixture['tenantId'], $fixture['company']->id)
            ->where('certification_id', $certification->id)
            ->pluck('skill_id')->all())
        ->toBe([$fixture['skillId']]);
});

test('renewal inserts a current successor and preserves the prior certificate history', function (): void {
    $fixture = certificationFixture();
    allowCertificationManagement();
    $store = app(SkillCertificationStore::class);
    $original = $store->record($fixture['actor'], $fixture['company']->id, certificationDraft($fixture));

    $renewed = $store->renew(
        $fixture['actor'],
        $fixture['company']->id,
        (int) $original->id,
        certificationDraft($fixture, [
            'externalReference' => 'CERT-002',
            'issuedOn' => new DateTimeImmutable('2027-01-02'),
            'expiresOn' => new DateTimeImmutable('2028-01-01'),
            'evidenceLink' => 'https://evidence.example.test/cert-002',
        ]),
    );

    expect($renewed->supersedes_certification_id)->toBe($original->id)
        ->and($renewed->isCurrent(new DateTimeImmutable('2027-02-01')))->toBeTrue()
        ->and($original->fresh()->external_reference)->toBe('CERT-001')
        ->and($store->history($fixture['company']->id, $fixture['employee']->id)->pluck('external_reference')->all())
        ->toBe(['CERT-002', 'CERT-001']);
});

test('certificate history is isolated by company', function (): void {
    $fixture = certificationFixture();
    allowCertificationManagement();
    $siblingCompany = NativeWorkforceFixture::create($fixture['tenantId'], WorkforceResourceType::Company);
    $siblingEmployee = NativeWorkforceFixture::create(
        $fixture['tenantId'],
        WorkforceResourceType::Employee,
        (int) $siblingCompany->id,
    );
    $siblingCategory = app(SkillCatalogStore::class)->defineCategory(
        (int) $siblingCompany->id,
        'safety',
        'Safety',
    );
    $siblingSkill = app(SkillCatalogStore::class)->defineSkill($siblingCompany->id, new SkillDraft(
        code: 'confined.space',
        name: 'Confined space entry',
        definition: 'Works safely in a confined space.',
        categoryId: (int) $siblingCategory->id,
        defaultAssessmentMethod: AssessmentMethod::DirectObservation,
    ));
    $store = app(SkillCertificationStore::class);

    $store->record($fixture['actor'], $fixture['company']->id, certificationDraft($fixture));
    $store->record($fixture['actor'], $siblingCompany->id, certificationDraft([
        'employee' => $siblingEmployee,
        'skillId' => (int) $siblingSkill->id,
    ]));

    expect($store->history($fixture['company']->id, $fixture['employee']->id))->toHaveCount(1)
        ->and($store->history($fixture['company']->id, $siblingEmployee->id))->toBeEmpty()
        ->and($store->history($siblingCompany->id, $siblingEmployee->id))->toHaveCount(1);
});

test('an actor without Skills management capability is refused before any certification row is written', function (): void {
    $fixture = certificationFixture();
    $before = SkillCertification::query()->forCompany($fixture['tenantId'], $fixture['company']->id)->count();

    expect(fn () => app(SkillCertificationStore::class)->record(
        $fixture['actor'],
        $fixture['company']->id,
        certificationDraft($fixture),
    ))->toThrow(AuthorizationDeniedException::class)
        ->and(SkillCertification::query()->forCompany($fixture['tenantId'], $fixture['company']->id)->count())
        ->toBe($before);
});

test('only a current certification explicitly mapped to the skill supplements backup coverage', function (): void {
    $fixture = certificationFixture();
    allowCertificationManagement();
    certificationScore($fixture, $fixture['employee'], current: 4);
    certificationScore($fixture, $fixture['otherEmployee'], current: 1);

    app(SkillCertificationStore::class)->record(
        $fixture['actor'],
        $fixture['company']->id,
        certificationDraft($fixture, [
            'employeeEntityId' => (int) $fixture['otherEmployee']->id,
            'skillIds' => [$fixture['skillId']],
        ]),
    );

    $row = app(CriticalSkillBackupCoverage::class)->rows(
        $fixture['tenantId'],
        $fixture['company']->id,
        null,
        new DateTimeImmutable('2026-06-01'),
    )[0];

    expect($row['holders'])->toBe(2)->and($row['covered'])->toBeTrue();
});

test('an expired mapped certification and an unmapped certification cannot claim coverage', function (): void {
    $fixture = certificationFixture();
    allowCertificationManagement();
    certificationScore($fixture, $fixture['employee'], current: 4);
    certificationScore($fixture, $fixture['otherEmployee'], current: 1);
    $store = app(SkillCertificationStore::class);

    $store->record(
        $fixture['actor'],
        $fixture['company']->id,
        certificationDraft($fixture, [
            'employeeEntityId' => (int) $fixture['otherEmployee']->id,
            'issuedOn' => new DateTimeImmutable('2025-01-01'),
            'expiresOn' => new DateTimeImmutable('2025-12-31'),
            'skillIds' => [$fixture['skillId']],
        ]),
    );
    $store->record(
        $fixture['actor'],
        $fixture['company']->id,
        certificationDraft($fixture, [
            'employeeEntityId' => (int) $fixture['otherEmployee']->id,
            'externalReference' => 'CERT-002',
            'skillIds' => [],
        ]),
    );

    $row = app(CriticalSkillBackupCoverage::class)->rows(
        $fixture['tenantId'],
        $fixture['company']->id,
        null,
        new DateTimeImmutable('2026-06-01'),
    )[0];

    expect($row['holders'])->toBe(1)->and($row['covered'])->toBeFalse();
});

test('certification and mappings are append-only', function (): void {
    $fixture = certificationFixture();
    allowCertificationManagement();
    $certification = app(SkillCertificationStore::class)->record(
        $fixture['actor'],
        $fixture['company']->id,
        certificationDraft($fixture),
    );
    $mapping = SkillCertificationSkill::query()
        ->forCompany($fixture['tenantId'], $fixture['company']->id)
        ->where('certification_id', $certification->id)
        ->sole();

    expect(fn () => $certification->update(['issuer' => 'Changed']))
        ->toThrow(InvalidSkillCertificationException::class)
        ->and(fn () => $mapping->delete())
        ->toThrow(InvalidSkillCertificationException::class)
        ->and(skillCertificationRefusedWrite(fn () => SkillCertification::query()
            ->forCompany($fixture['tenantId'], $fixture['company']->id)
            ->whereKey($certification->id)
            ->update(['issuer' => 'Changed'])))
        ->toThrow(QueryException::class)
        ->and(skillCertificationRefusedWrite(fn () => SkillCertificationSkill::query()
            ->forCompany($fixture['tenantId'], $fixture['company']->id)
            ->whereKey($mapping->id)
            ->delete()))
        ->toThrow(QueryException::class);
});

test('a predecessor certificate does not return to coverage when its renewal expires first', function (): void {
    // astra's and composer's [P1] on #476, reproduced verbatim from astra's
    // case. certificationHolders() derived $superseded from the already
    // date-filtered certification set, so an expired successor stopped hiding
    // its predecessor and stale qualification evidence counted as current
    // cover. Derive superseded ids from every company-scoped certificate.
    $fixture = certificationFixture();
    allowCertificationManagement();
    certificationScore($fixture, $fixture['employee'], current: 4);
    certificationScore($fixture, $fixture['otherEmployee'], current: 1);
    $store = app(SkillCertificationStore::class);

    $original = $store->record($fixture['actor'], $fixture['company']->id, certificationDraft($fixture, [
        'employeeEntityId' => (int) $fixture['otherEmployee']->id,
        'expiresOn' => new DateTimeImmutable('2027-12-31'),
        'skillIds' => [$fixture['skillId']],
    ]));

    // The renewal expires BEFORE the certificate it replaced.
    $store->renew($fixture['actor'], $fixture['company']->id, (int) $original->id, certificationDraft($fixture, [
        'employeeEntityId' => (int) $fixture['otherEmployee']->id,
        'externalReference' => 'CERT-RENEW',
        'issuedOn' => new DateTimeImmutable('2026-06-01'),
        'expiresOn' => new DateTimeImmutable('2027-01-31'),
        'skillIds' => [$fixture['skillId']],
    ]));

    $row = app(CriticalSkillBackupCoverage::class)
        ->rows($fixture['tenantId'], $fixture['company']->id, null, new DateTimeImmutable('2027-02-01'))[0];

    // One holder: the scored employee. The superseded original must stay
    // buried even though its own expiry has not arrived.
    expect($row['holders'])->toBe(1);
});

test('a certificate issued in the future does not count as cover today', function (): void {
    // desktop-sol's [P1] on #476: the candidate query filters on expires_on
    // only, and isCurrent() checks renewal_status and expires_on but never
    // requires issued_on <= asOf, so a certificate that does not exist yet
    // raises today's holder count.
    $fixture = certificationFixture();
    allowCertificationManagement();
    certificationScore($fixture, $fixture['employee'], current: 4);
    certificationScore($fixture, $fixture['otherEmployee'], current: 1);

    app(SkillCertificationStore::class)->record($fixture['actor'], $fixture['company']->id, certificationDraft($fixture, [
        'employeeEntityId' => (int) $fixture['otherEmployee']->id,
        'issuedOn' => new DateTimeImmutable('2030-01-01'),
        'expiresOn' => new DateTimeImmutable('2031-01-01'),
        'skillIds' => [$fixture['skillId']],
    ]));

    $row = app(CriticalSkillBackupCoverage::class)
        ->rows($fixture['tenantId'], $fixture['company']->id, null, new DateTimeImmutable('2026-06-01'))[0];

    expect($row['holders'])->toBe(1);
});

test('an employee whose only evidence is a certificate still counts as a holder', function (): void {
    // desktop-sol's [P1] on #476: departmentByEmployee() is built solely from
    // employee ids present in critical score rows, so a certificate-only
    // holder fails the array_key_exists check and is silently skipped. A
    // certificate is qualification evidence in its own right.
    $fixture = certificationFixture();
    allowCertificationManagement();
    // Only the first employee has a score; otherEmployee has none at all.
    certificationScore($fixture, $fixture['employee'], current: 4);

    app(SkillCertificationStore::class)->record($fixture['actor'], $fixture['company']->id, certificationDraft($fixture, [
        'employeeEntityId' => (int) $fixture['otherEmployee']->id,
        'skillIds' => [$fixture['skillId']],
    ]));

    $row = app(CriticalSkillBackupCoverage::class)
        ->rows($fixture['tenantId'], $fixture['company']->id, null, new DateTimeImmutable('2026-06-01'))[0];

    expect($row['holders'])->toBe(2);
});

test('a future-dated successor does not suppress the predecessor before its issue date', function (): void {
    // desktop-sol's companion to the resurrection case: supersession must be
    // as-of the evaluation day. A renewal recorded with a future issued_on
    // must not hide the still-current predecessor until that day arrives.
    $fixture = certificationFixture();
    allowCertificationManagement();
    certificationScore($fixture, $fixture['employee'], current: 4);
    certificationScore($fixture, $fixture['otherEmployee'], current: 1);
    $store = app(SkillCertificationStore::class);

    $original = $store->record($fixture['actor'], $fixture['company']->id, certificationDraft($fixture, [
        'employeeEntityId' => (int) $fixture['otherEmployee']->id,
        'issuedOn' => new DateTimeImmutable('2026-01-01'),
        'expiresOn' => new DateTimeImmutable('2027-12-31'),
        'skillIds' => [$fixture['skillId']],
    ]));

    $store->renew($fixture['actor'], $fixture['company']->id, (int) $original->id, certificationDraft($fixture, [
        'employeeEntityId' => (int) $fixture['otherEmployee']->id,
        'externalReference' => 'CERT-FUTURE',
        'issuedOn' => new DateTimeImmutable('2027-06-01'),
        'expiresOn' => new DateTimeImmutable('2028-06-01'),
        'skillIds' => [$fixture['skillId']],
    ]));

    $row = app(CriticalSkillBackupCoverage::class)
        ->rows($fixture['tenantId'], $fixture['company']->id, null, new DateTimeImmutable('2027-01-15'))[0];

    expect($row['holders'])->toBe(2);
});

test('raw inserts cannot fork one predecessor or attach an employee from another company', function (): void {
    $fixture = certificationFixture();
    allowCertificationManagement();
    $store = app(SkillCertificationStore::class);
    $original = $store->record($fixture['actor'], $fixture['company']->id, certificationDraft($fixture));
    $store->renew(
        $fixture['actor'],
        $fixture['company']->id,
        (int) $original->id,
        certificationDraft($fixture, [
            'externalReference' => 'CERT-002',
            'issuedOn' => new DateTimeImmutable('2027-01-02'),
            'expiresOn' => new DateTimeImmutable('2028-01-01'),
            'evidenceLink' => 'https://evidence.example.test/cert-002',
        ]),
    );
    $siblingCompany = NativeWorkforceFixture::create($fixture['tenantId'], WorkforceResourceType::Company);
    $foreignEmployee = NativeWorkforceFixture::create(
        $fixture['tenantId'],
        WorkforceResourceType::Employee,
        (int) $siblingCompany->id,
    );
    $now = now();

    expect(skillCertificationRefusedWrite(fn () => DB::table('people_connector_skill_certifications')->insert([
        'tenant_id' => $fixture['tenantId'],
        'company_entity_id' => $fixture['company']->id,
        'employee_entity_id' => $fixture['employee']->id,
        'issuer' => 'Forked Board',
        'external_reference' => 'CERT-FORK',
        'issued_on' => '2027-02-01',
        'expires_on' => '2028-02-01',
        'renewal_status' => 'current',
        'evidence_link' => 'https://evidence.example.test/fork',
        'supersedes_certification_id' => $original->id,
        'created_at' => $now,
        'updated_at' => $now,
    ])))->toThrow(QueryException::class)
        ->and(skillCertificationRefusedWrite(fn () => DB::table('people_connector_skill_certifications')->insert([
            'tenant_id' => $fixture['tenantId'],
            'company_entity_id' => $fixture['company']->id,
            'employee_entity_id' => $foreignEmployee->id,
            'issuer' => 'Foreign Board',
            'external_reference' => 'CERT-FOREIGN',
            'issued_on' => '2026-02-01',
            'expires_on' => '2027-02-01',
            'renewal_status' => 'current',
            'evidence_link' => 'https://evidence.example.test/foreign',
            'supersedes_certification_id' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ])))->toThrow(QueryException::class)
        ->and(SkillCertification::query()->forCompany($fixture['tenantId'], $fixture['company']->id)->count())
        ->toBe(2);
});
