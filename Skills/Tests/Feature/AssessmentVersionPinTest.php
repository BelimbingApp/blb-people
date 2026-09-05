<?php

use App\Base\Tenancy\Contracts\TenantContext;
use App\Core\User\Models\User;
use App\Domains\People\Provider\Enums\WorkforceResourceType;
use App\Domains\People\Skills\Contracts\ResolvesSkillRequirements;
use App\Domains\People\Skills\Data\AssessmentDraft;
use App\Domains\People\Skills\Data\RequirementItemDraft;
use App\Domains\People\Skills\Data\RequirementProfileDraft;
use App\Domains\People\Skills\Data\RequirementSelectorDraft;
use App\Domains\People\Skills\Data\ResolvedSkillRequirement;
use App\Domains\People\Skills\Data\SkillDraft;
use App\Domains\People\Skills\Enums\AssessmentCycle;
use App\Domains\People\Skills\Enums\AssessmentMethod;
use App\Domains\People\Skills\Enums\CriticalClassification;
use App\Domains\People\Skills\Enums\RequirementCriticality;
use App\Domains\People\Skills\Enums\RequirementProfileStatus;
use App\Domains\People\Skills\Enums\SelectorType;
use App\Domains\People\Skills\Enums\SkillScope;
use App\Domains\People\Skills\Exceptions\InvalidAssessmentException;
use App\Domains\People\Skills\Models\RequirementProfile;
use App\Domains\People\Skills\Models\SkillAssessment;
use App\Domains\People\Skills\Services\AssessmentStore;
use App\Domains\People\Skills\Services\RequirementProfileStore;
use App\Domains\People\Skills\Services\SkillAudience;
use App\Domains\People\Skills\Services\SkillCatalogDefaults;
use App\Domains\People\Skills\Services\SkillCatalogStore;
use App\Domains\People\Skills\Tests\Support\NativeWorkforceFixture;

/*
 * Self-contained: every helper is prefixed pin and lives here, so the file
 * passes or fails alone for its own reasons.
 */

afterEach(function (): void {
    app(TenantContext::class)->clear();
});

final class PinFixtureRequirements implements ResolvesSkillRequirements
{
    /** @param list<ResolvedSkillRequirement> $rows */
    public function __construct(private array $rows) {}

    public function requirementsFor(array $employeeData, ?DateTimeInterface $asOf = null): array
    {
        return $this->rows;
    }
}

function pinOpenAudience(): void
{
    app()->instance(SkillAudience::class, new class extends SkillAudience
    {
        public function __construct() {}

        public function authorizeAssessmentSubmission(User $user, int $companyEntityId, int $employeeEntityId): void {}

        public function authorizeHodVerification(User $user, int $companyEntityId, int $employeeEntityId): void {}

        public function authorizeAssessmentFinalization(User $user, int $companyEntityId, int $employeeEntityId): void {}
    });
}

/**
 * Build a profile in the requested state through the governed lifecycle.
 *
 * The model refuses a profile that does not enter as a draft, which is the
 * lifecycle doing its job. Reaching around it with a direct insert would build
 * a fixture the application can never produce, and then test against it.
 */
function pinProfile(int $companyEntityId, int $skillId, RequirementProfileStatus $status): RequirementProfile
{
    $store = app(RequirementProfileStore::class);
    $profile = $store->draft($companyEntityId, new RequirementProfileDraft(
        code: 'pin.ops',
        name: 'Operations',
        selectors: [new RequirementSelectorDraft(SelectorType::Company)],
        items: [new RequirementItemDraft(
            skillId: $skillId,
            sequence: 1,
            requiredLevel: 4,
            criticality: RequirementCriticality::Critical,
            weightPercent: 100.0,
        )],
        effectiveDate: new DateTimeImmutable('2026-01-01'),
    ));

    if ($status === RequirementProfileStatus::Draft) {
        return $profile;
    }

    $profile = $store->publish($companyEntityId, (int) $profile->id);

    return $status === RequirementProfileStatus::Retired
        ? $store->retire($companyEntityId, (int) $profile->id)
        : $profile;
}

/** @return array{tenantId: int, companyEntityId: int, employeeEntityId: int, skillId: int} */
function pinFixture(string $name): array
{
    $tenant = createTenant(['name' => $name]);
    $tenantId = (int) $tenant->id;
    app(TenantContext::class)->set($tenantId);

    $company = NativeWorkforceFixture::create($tenantId, WorkforceResourceType::Company);
    $employee = NativeWorkforceFixture::create($tenantId, WorkforceResourceType::Employee);

    $category = app(SkillCatalogStore::class)->defineCategory((int) $company->id, 'ops', 'Operations');
    $skill = app(SkillCatalogStore::class)->defineSkill((int) $company->id, new SkillDraft(
        code: 'forklift.operation',
        name: 'Forklift Operation',
        definition: 'Operates a counterbalance forklift.',
        categoryId: (int) $category->id,
        scope: SkillScope::Shared,
        criticalClassification: CriticalClassification::Safety,
        evidenceGuide: 'Observed lift cycle.',
        defaultAssessmentMethod: AssessmentMethod::DirectObservation,
        defaultReassessmentMonths: 12,
    ));
    app(SkillCatalogDefaults::class)->install((int) $company->id);
    pinOpenAudience();

    return [
        'tenantId' => $tenantId,
        'companyEntityId' => (int) $company->id,
        'employeeEntityId' => (int) $employee->id,
        'skillId' => (int) $skill->id,
    ];
}

function pinRequirements(int $skillId, int $profileId, int $version = 1): void
{
    app()->instance(ResolvesSkillRequirements::class, new PinFixtureRequirements([
        new ResolvedSkillRequirement(
            requirementReference: 'pin.ops',
            requirementVersion: $version,
            requirementProfileId: $profileId,
            skillId: $skillId,
            requiredLevel: 4,
            criticality: RequirementCriticality::Critical,
            mandatoryGate: true,
        ),
    ]));
}

function pinDraft(int $employeeEntityId, int $skillId): AssessmentDraft
{
    return new AssessmentDraft(
        employeeEntityId: $employeeEntityId,
        skillId: $skillId,
        assessedLevel: 2,
        method: AssessmentMethod::DirectObservation,
        cycle: AssessmentCycle::Annual,
        assessedAt: now(),
        evidence: 'Observed three compliant lift cycles with valid licence.',
        notes: null,
        assessorUserId: 9,
        weightPercent: 10.0,
    );
}

test('an assessment records the requirement profile version it was taken against', function (): void {
    $f = pinFixture('Pin Records Tenant');
    $profile = pinProfile($f['companyEntityId'], $f['skillId'], RequirementProfileStatus::Published);
    pinRequirements($f['skillId'], (int) $profile->id);

    $assessment = app(AssessmentStore::class)->submit(
        User::factory()->make(['id' => 9]),
        $f['companyEntityId'],
        pinDraft($f['employeeEntityId'], $f['skillId']),
    );

    expect((int) $assessment->requirement_profile_id)->toBe((int) $profile->id);
});

test('assessing against a draft version is refused', function (): void {
    $f = pinFixture('Pin Draft Tenant');
    $profile = pinProfile($f['companyEntityId'], $f['skillId'], RequirementProfileStatus::Draft);
    pinRequirements($f['skillId'], (int) $profile->id);

    // A draft is a proposal. Assessing against it would produce evidence
    // against requirements nobody has approved.
    expect(fn () => app(AssessmentStore::class)->submit(
        User::factory()->make(['id' => 9]),
        $f['companyEntityId'],
        pinDraft($f['employeeEntityId'], $f['skillId']),
    ))->toThrow(InvalidAssessmentException::class);

    expect(SkillAssessment::query()->forTenant($f['tenantId'])->count())->toBe(0);
});

test('assessing against a retired version is refused', function (): void {
    $f = pinFixture('Pin Retired Tenant');
    $profile = pinProfile($f['companyEntityId'], $f['skillId'], RequirementProfileStatus::Retired);
    pinRequirements($f['skillId'], (int) $profile->id);

    expect(fn () => app(AssessmentStore::class)->submit(
        User::factory()->make(['id' => 9]),
        $f['companyEntityId'],
        pinDraft($f['employeeEntityId'], $f['skillId']),
    ))->toThrow(InvalidAssessmentException::class);

    expect(SkillAssessment::query()->forTenant($f['tenantId'])->count())->toBe(0);
});

test('assessing against another company version is refused', function (): void {
    $f = pinFixture('Pin Cross Company Tenant');
    $sibling = NativeWorkforceFixture::create($f['tenantId'], WorkforceResourceType::Company);
    $foreign = pinProfile((int) $sibling->id, $f['skillId'], RequirementProfileStatus::Published);
    pinRequirements($f['skillId'], (int) $foreign->id);

    // Same tenant, different company. A published version is still not this
    // company's policy, and pinning to it would attribute one company's
    // requirements to another's people.
    expect(fn () => app(AssessmentStore::class)->submit(
        User::factory()->make(['id' => 9]),
        $f['companyEntityId'],
        pinDraft($f['employeeEntityId'], $f['skillId']),
    ))->toThrow(InvalidAssessmentException::class);

    expect(SkillAssessment::query()->forTenant($f['tenantId'])->count())->toBe(0);
});

test('a version retired after an assessment stays readable through it', function (): void {
    $f = pinFixture('Pin Historical Read Tenant');
    $profile = pinProfile($f['companyEntityId'], $f['skillId'], RequirementProfileStatus::Published);
    pinRequirements($f['skillId'], (int) $profile->id);
    $assessment = app(AssessmentStore::class)->submit(
        User::factory()->make(['id' => 9]),
        $f['companyEntityId'],
        pinDraft($f['employeeEntityId'], $f['skillId']),
    );

    // Publishing a successor retires the predecessor, so "superseded" is a
    // relationship rather than a status spelling — see
    // docs/contracts/requirement-versioning.md. Retiring is therefore how this
    // case actually arises, and retirement must not erase the evidence.
    app(RequirementProfileStore::class)->retire($f['companyEntityId'], (int) $profile->id);

    $pinned = RequirementProfile::query()
        ->forTenant($f['tenantId'])
        ->whereKey($assessment->refresh()->requirement_profile_id)
        ->first();

    expect($pinned)->not->toBeNull()
        ->and((int) $pinned->id)->toBe((int) $profile->id)
        ->and($pinned->version)->toBe(1);
});
