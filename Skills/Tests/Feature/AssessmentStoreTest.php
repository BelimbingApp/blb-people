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
use App\Domains\People\Skills\Enums\AssessmentResultBand;
use App\Domains\People\Skills\Enums\AssessmentStatus;
use App\Domains\People\Skills\Enums\CriticalClassification;
use App\Domains\People\Skills\Enums\HodVerification;
use App\Domains\People\Skills\Enums\RequirementCriticality;
use App\Domains\People\Skills\Enums\SelectorType;
use App\Domains\People\Skills\Enums\SkillScope;
use App\Domains\People\Skills\Events\SkillAssessmentFinalized;
use App\Domains\People\Skills\Exceptions\FinalizedAssessmentImmutableException;
use App\Domains\People\Skills\Exceptions\InvalidAssessmentException;
use App\Domains\People\Skills\Models\AssessmentDecision;
use App\Domains\People\Skills\Models\EmployeeSkillScore;
use App\Domains\People\Skills\Models\ProficiencyScale;
use App\Domains\People\Skills\Models\SkillAssessment;
use App\Domains\People\Skills\Services\AssessmentStore;
use App\Domains\People\Skills\Services\AssessmentWorkflowContext;
use App\Domains\People\Skills\Services\RequirementProfileStore;
use App\Domains\People\Skills\Services\SkillAudience;
use App\Domains\People\Skills\Services\SkillCatalogDefaults;
use App\Domains\People\Skills\Services\SkillCatalogStore;
use App\Domains\People\Skills\Tests\Support\NativeWorkforceFixture;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

afterEach(function (): void {
    app(TenantContext::class)->clear();
});

final class AssessmentFixtureRequirements implements ResolvesSkillRequirements
{
    /** @param list<ResolvedSkillRequirement> $rows */
    public function __construct(private array $rows) {}

    public function requirementsFor(array $employeeData, ?DateTimeInterface $asOf = null): array
    {
        return $this->rows;
    }
}

/**
 * @return array{int, int, int, int} [tenantId, companyEntityId, employeeEntityId, skillId]
 */
function assessmentWorkflowTestAudience(): void
{
    app()->instance(SkillAudience::class, new class extends SkillAudience
    {
        public function __construct() {}

        public function authorizeAssessmentSubmission(User $user, int $companyEntityId, int $employeeEntityId): void {}

        public function authorizeHodVerification(User $user, int $companyEntityId, int $employeeEntityId): void {}

        public function authorizeAssessmentFinalization(User $user, int $companyEntityId, int $employeeEntityId): void {}
    });
}

function assessmentFixture(): array
{
    $tenant = createTenant(['name' => 'Assessment Tenant']);
    app(TenantContext::class)->set((int) $tenant->id);
    $tenantId = (int) $tenant->id;

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

    app()->instance(ResolvesSkillRequirements::class, new AssessmentFixtureRequirements([
        new ResolvedSkillRequirement(
            requirementReference: 'fixture.ops',
            requirementVersion: 2,
            skillId: (int) $skill->id,
            requiredLevel: 4,
            criticality: RequirementCriticality::Critical,
            mandatoryGate: true,
        ),
    ]));

    assessmentWorkflowTestAudience();

    return [$tenantId, (int) $company->id, (int) $employee->id, (int) $skill->id];
}

function assessmentActor(int $id): User
{
    return User::factory()->make(['id' => $id]);
}

function assessmentDraft(int $employeeEntityId, int $skillId, array $overrides = []): AssessmentDraft
{
    $base = [
        'employeeEntityId' => $employeeEntityId,
        'skillId' => $skillId,
        'assessedLevel' => 2,
        'method' => AssessmentMethod::DirectObservation,
        'cycle' => AssessmentCycle::Annual,
        'assessedAt' => now(),
        'evidence' => 'Observed three compliant lift cycles with valid licence.',
        'notes' => null,
        'assessorUserId' => 9,
        'weightPercent' => 10.0,
    ];

    return new AssessmentDraft(...array_merge($base, $overrides));
}

function finalizeVerifiedAssessment(
    AssessmentStore $store,
    int $companyEntityId,
    AssessmentDraft $draft,
    ?int $supersedesAssessmentId = null,
    int $hodVerifierUserId = 10,
    array $employeeData = [],
): SkillAssessment {
    $submitted = $store->submit(
        assessmentActor(9),
        $companyEntityId,
        $draft,
        $employeeData,
        supersedesAssessmentId: $supersedesAssessmentId,
    );
    $pending = $store->requestHodVerification(assessmentActor(9), $companyEntityId, (int) $submitted->id);
    $store->verifyHod(assessmentActor($hodVerifierUserId), $companyEntityId, (int) $pending->id, 'Verified against the submitted evidence.');

    return $store->finalizeVerified(assessmentActor($hodVerifierUserId), $companyEntityId, (int) $pending->id);
}

test('finalize snapshots requirement and projects gap from the published contract', function (): void {
    Event::fake([SkillAssessmentFinalized::class]);
    [$tenantId, $companyEntityId, $employeeEntityId, $skillId] = assessmentFixture();

    $assessment = finalizeVerifiedAssessment(
        app(AssessmentStore::class),
        $companyEntityId,
        assessmentDraft($employeeEntityId, $skillId),
    );

    expect($assessment->status)->toBe(AssessmentStatus::Finalized)
        ->and($assessment->requirement_reference)->toBe('fixture.ops')
        ->and($assessment->requirement_version)->toBe(2)
        ->and($assessment->required_level)->toBe(4)
        ->and($assessment->assessed_level)->toBe(2)
        ->and($assessment->gap)->toBe(2)
        ->and((float) $assessment->weighted_gap)->toBe(20.0)
        ->and((float) $assessment->priority_score)->toBe(60.0)
        ->and($assessment->result_band)->toBe(AssessmentResultBand::MajorGap)
        ->and($assessment->mandatory_gate)->toBeTrue()
        ->and($assessment->scale_id)->not->toBeNull()
        ->and($assessment->scale_version)->toBe(1)
        ->and($assessment->hod_verification)->toBe(HodVerification::Verified)
        ->and($assessment->hod_verifier_user_id)->toBe(10)
        ->and($assessment->hod_decision_notes)->toBe('Verified against the submitted evidence.')
        ->and($assessment->finalized_at)->not->toBeNull();

    $scale = ProficiencyScale::query()->forCompany($tenantId, $companyEntityId)->whereKey($assessment->scale_id)->sole();
    expect($scale->code)->toBe(SkillCatalogDefaults::SCALE_CODE)
        ->and($scale->version)->toBe($assessment->scale_version);

    $score = EmployeeSkillScore::query()
        ->forCompany($tenantId, $companyEntityId)
        ->where('employee_entity_id', $employeeEntityId)
        ->where('skill_id', $skillId)
        ->sole();

    expect($score->current_level)->toBe(2)
        ->and($score->gap)->toBe(2)
        ->and($score->source_assessment_id)->toBe($assessment->id);

    Event::assertDispatched(SkillAssessmentFinalized::class);
});

test('score projection waits for an independent HOD decision', function (): void {
    Event::fake([SkillAssessmentFinalized::class]);
    [$tenantId, $companyEntityId, $employeeEntityId, $skillId] = assessmentFixture();
    $store = app(AssessmentStore::class);
    $draft = assessmentDraft($employeeEntityId, $skillId);

    $submitted = $store->submit(assessmentActor(9), $companyEntityId, $draft);
    expect($submitted->status)->toBe(AssessmentStatus::Submitted)
        ->and(EmployeeSkillScore::query()->forCompany($tenantId, $companyEntityId)->count())->toBe(0);

    $pending = $store->requestHodVerification(assessmentActor(9), $companyEntityId, (int) $submitted->id);
    expect($pending->status)->toBe(AssessmentStatus::PendingHodVerification)
        ->and($pending->isAwaitingHodVerification())->toBeTrue();

    expect(fn () => $store->verifyHod(assessmentActor(9), $companyEntityId, (int) $pending->id))
        ->toThrow(InvalidAssessmentException::class, 'assessor');

    $returned = $store->returnForCorrection(
        assessmentActor(10),
        $companyEntityId,
        (int) $pending->id,
        'Attach the observation record before resubmission.',
    );
    expect($returned->status)->toBe(AssessmentStatus::Returned)
        ->and($returned->hod_verification)->toBe(HodVerification::Rejected)
        ->and(EmployeeSkillScore::query()->forCompany($tenantId, $companyEntityId)->count())->toBe(0);

    expect(fn () => $store->finalizeVerified(assessmentActor(10), $companyEntityId, (int) $returned->id))
        ->toThrow(InvalidAssessmentException::class, 'pending HOD verification');

    Event::assertNotDispatched(SkillAssessmentFinalized::class);
});

test('a pending assessment cannot finalize before HOD verification', function (): void {
    Event::fake([SkillAssessmentFinalized::class]);
    [$tenantId, $companyEntityId, $employeeEntityId, $skillId] = assessmentFixture();
    $store = app(AssessmentStore::class);

    $submitted = $store->submit(assessmentActor(9), $companyEntityId, assessmentDraft($employeeEntityId, $skillId));
    $pending = $store->requestHodVerification(assessmentActor(9), $companyEntityId, (int) $submitted->id);

    expect(fn () => $store->finalizeVerified(assessmentActor(10), $companyEntityId, (int) $pending->id))
        ->toThrow(InvalidAssessmentException::class, 'HOD verification is required');

    expect($pending->fresh()->status)->toBe(AssessmentStatus::PendingHodVerification)
        ->and(EmployeeSkillScore::query()->forCompany($tenantId, $companyEntityId)->count())->toBe(0);

    Event::assertNotDispatched(SkillAssessmentFinalized::class);
});

test('the assessor cannot finalize an independently verified assessment', function (): void {
    Event::fake([SkillAssessmentFinalized::class]);
    [$tenantId, $companyEntityId, $employeeEntityId, $skillId] = assessmentFixture();
    $store = app(AssessmentStore::class);

    $submitted = $store->submit(assessmentActor(9), $companyEntityId, assessmentDraft($employeeEntityId, $skillId));
    $pending = $store->requestHodVerification(assessmentActor(9), $companyEntityId, (int) $submitted->id);
    $verified = $store->verifyHod(assessmentActor(10), $companyEntityId, (int) $pending->id);

    expect(fn () => $store->finalizeVerified(assessmentActor(9), $companyEntityId, (int) $verified->id))
        ->toThrow(InvalidAssessmentException::class, 'assessor cannot finalize');

    expect($verified->fresh()->status)->toBe(AssessmentStatus::PendingHodVerification)
        ->and(EmployeeSkillScore::query()->forCompany($tenantId, $companyEntityId)->count())->toBe(0);

    Event::assertNotDispatched(SkillAssessmentFinalized::class);
});

test('only submitted assessments can enter the HOD verification queue', function (): void {
    [, $companyEntityId, $employeeEntityId, $skillId] = assessmentFixture();
    $store = app(AssessmentStore::class);
    $submitted = $store->submit(assessmentActor(9), $companyEntityId, assessmentDraft($employeeEntityId, $skillId));

    $draftAttributes = $submitted->getAttributes();
    unset($draftAttributes['id']);
    $draftAttributes['status'] = AssessmentStatus::Draft->value;
    $draftAttributes['hod_verification'] = HodVerification::Pending->value;
    $draftAttributes['hod_verifier_user_id'] = null;
    $draftAttributes['hod_verified_at'] = null;
    $draftAttributes['hod_decision_notes'] = null;
    $draftAttributes['finalized_at'] = null;
    $draftAttributes['finalized_by_user_id'] = null;
    $draft = SkillAssessment::query()->create($draftAttributes);

    expect(fn () => $store->requestHodVerification(assessmentActor(9), $companyEntityId, (int) $draft->id))
        ->toThrow(InvalidAssessmentException::class, 'Only a submitted assessment');
});

test('HOD decisions are accepted only once for a pending assessment', function (): void {
    [, $companyEntityId, $employeeEntityId, $skillId] = assessmentFixture();
    $store = app(AssessmentStore::class);

    $submitted = $store->submit(assessmentActor(9), $companyEntityId, assessmentDraft($employeeEntityId, $skillId));
    $pending = $store->requestHodVerification(assessmentActor(9), $companyEntityId, (int) $submitted->id);
    $verified = $store->verifyHod(assessmentActor(10), $companyEntityId, (int) $pending->id);

    expect(fn () => $store->verifyHod(assessmentActor(10), $companyEntityId, (int) $verified->id))
        ->toThrow(InvalidAssessmentException::class, 'pending, undecided');

    $secondSubmitted = $store->submit(assessmentActor(9), $companyEntityId, assessmentDraft($employeeEntityId, $skillId, [
        'assessedLevel' => 3,
    ]));
    $secondPending = $store->requestHodVerification(assessmentActor(9), $companyEntityId, (int) $secondSubmitted->id);
    $returned = $store->returnForCorrection(
        assessmentActor(10),
        $companyEntityId,
        (int) $secondPending->id,
        'Attach the observation record before resubmission.',
    );

    expect(fn () => $store->verifyHod(assessmentActor(10), $companyEntityId, (int) $returned->id))
        ->toThrow(InvalidAssessmentException::class, 'pending, undecided');
});

test('returned assessments resubmit through a new governed lineage', function (): void {
    [$tenantId, $companyEntityId, $employeeEntityId, $skillId] = assessmentFixture();
    $store = app(AssessmentStore::class);

    $submitted = $store->submit(assessmentActor(9), $companyEntityId, assessmentDraft($employeeEntityId, $skillId));
    $pending = $store->requestHodVerification(assessmentActor(9), $companyEntityId, (int) $submitted->id);
    $returned = $store->returnForCorrection(
        assessmentActor(10),
        $companyEntityId,
        (int) $pending->id,
        'Attach the observation record before resubmission.',
    );

    $corrected = $store->resubmitForCorrection(
        assessmentActor(9),
        $companyEntityId,
        (int) $returned->id,
        assessmentDraft($employeeEntityId, $skillId, ['evidence' => 'Attached observation record confirms the lift cycles.']),
    );

    expect($corrected->status)->toBe(AssessmentStatus::PendingHodVerification)
        ->and($corrected->supersedes_assessment_id)->toBe($returned->id)
        ->and(SkillAssessment::query()->forCompany($tenantId, $companyEntityId)->count())->toBe(2)
        ->and(AssessmentDecision::query()->forCompany($tenantId, $companyEntityId)->where('assessment_id', $returned->id)->count())->toBe(3)
        ->and(AssessmentDecision::query()->forCompany($tenantId, $companyEntityId)->where('assessment_id', $corrected->id)->count())->toBe(2);

    expect(fn () => $store->resubmitForCorrection(
        assessmentActor(9),
        $companyEntityId,
        (int) $returned->id,
        assessmentDraft($employeeEntityId, $skillId),
    ))->toThrow(InvalidAssessmentException::class, 'already has a correction');

    expect(fn () => $store->resubmitForCorrection(
        assessmentActor(10),
        $companyEntityId,
        (int) $returned->id,
        assessmentDraft($employeeEntityId, $skillId),
    ))->toThrow(InvalidAssessmentException::class, 'original assessor');
});

test('assessment workflow rejects spoofed actors and direct lifecycle writes', function (): void {
    [, $companyEntityId, $employeeEntityId, $skillId] = assessmentFixture();
    $store = app(AssessmentStore::class);
    $draft = assessmentDraft($employeeEntityId, $skillId);

    expect(fn () => $store->submit(
        assessmentActor(10),
        $companyEntityId,
        $draft,
    ))->toThrow(InvalidAssessmentException::class, 'authenticated actor');

    $submitted = $store->submit(assessmentActor(9), $companyEntityId, $draft);
    $submitted->status = AssessmentStatus::PendingHodVerification;
    expect(fn () => $submitted->save())
        ->toThrow(InvalidAssessmentException::class, 'AssessmentStore workflow');

    expect(fn () => DB::transaction(static fn (): int => DB::table('people_connector_skill_assessments')
        ->where('id', $submitted->id)
        ->update(['status' => AssessmentStatus::PendingHodVerification->value])))
        ->toThrow(QueryException::class);

    expect(fn () => DB::transaction(static fn (): int => DB::table('people_connector_skill_assessments')
        ->where('id', $submitted->id)
        ->update(['status' => AssessmentStatus::Finalized->value])))
        ->toThrow(QueryException::class);

    $rawInsert = $submitted->getAttributes();
    unset($rawInsert['id']);
    expect(fn () => DB::transaction(static fn (): bool => DB::table('people_connector_skill_assessments')->insert($rawInsert)))
        ->toThrow(QueryException::class);

    expect(fn () => AssessmentWorkflowContext::runStoreMutation(static function () use ($submitted): bool {
        return DB::table('people_connector_skill_assessment_decisions')->insert([
            'tenant_id' => $submitted->tenant_id,
            'company_entity_id' => $submitted->company_entity_id + 1,
            'employee_entity_id' => $submitted->employee_entity_id,
            'skill_id' => $submitted->skill_id,
            'assessment_id' => $submitted->id,
            'decision' => 'forged-company',
            'actor_user_id' => 10,
            'created_at' => now(),
        ]);
    }))->toThrow(QueryException::class);
    expect($submitted->decisions()->count())->toBe(1);

    $forgedInsert = array_replace($rawInsert, [
        'status' => AssessmentStatus::Finalized->value,
        'hod_verification' => HodVerification::Verified->value,
        'hod_verifier_user_id' => 10,
        'hod_verified_at' => now(),
        'finalized_at' => now(),
        'finalized_by_user_id' => 10,
    ]);
    expect(fn () => AssessmentWorkflowContext::runStoreMutation(static fn (): bool => DB::table('people_connector_skill_assessments')->insert($forgedInsert)))
        ->toThrow(QueryException::class);

    $pending = $store->requestHodVerification(assessmentActor(9), $companyEntityId, (int) $submitted->id);
    expect(fn () => DB::transaction(static fn (): mixed => AssessmentWorkflowContext::runStoreMutation(static fn (): int => DB::table('people_connector_skill_assessments')
        ->where('id', $pending->id)
        ->update([
            'hod_verification' => HodVerification::Verified->value,
            'hod_verifier_user_id' => 9,
            'hod_verified_at' => now(),
        ]))))
        ->toThrow(QueryException::class);
});

test('evidence is mandatory and scale values fail closed', function (): void {
    [, $companyEntityId, $employeeEntityId, $skillId] = assessmentFixture();
    $store = app(AssessmentStore::class);

    expect(fn () => finalizeVerifiedAssessment($store, $companyEntityId, assessmentDraft($employeeEntityId, $skillId, [
        'evidence' => '  ',
    ])))->toThrow(InvalidAssessmentException::class, 'Evidence');

    expect(fn () => finalizeVerifiedAssessment($store, $companyEntityId, assessmentDraft($employeeEntityId, $skillId, [
        'assessedLevel' => 9,
    ])))->toThrow(InvalidAssessmentException::class, '0 and 5');
});

test('finalized assessments are immutable; supersession keeps history and refreshes the score', function (): void {
    [$tenantId, $companyEntityId, $employeeEntityId, $skillId] = assessmentFixture();
    $store = app(AssessmentStore::class);

    $first = finalizeVerifiedAssessment($store, $companyEntityId, assessmentDraft($employeeEntityId, $skillId, [
        'assessedLevel' => 1,
    ]));

    expect(fn () => $first->update(['notes' => 'tamper']))
        ->toThrow(FinalizedAssessmentImmutableException::class);

    expect(fn () => DB::transaction(fn () => SkillAssessment::query()
        ->forCompany($tenantId, $companyEntityId)
        ->whereKey($first->id)
        ->update(['notes' => 'raw tamper'])))
        ->toThrow(QueryException::class);

    $second = finalizeVerifiedAssessment(
        $store,
        $companyEntityId,
        assessmentDraft($employeeEntityId, $skillId, ['assessedLevel' => 4]),
        supersedesAssessmentId: (int) $first->id,
    );

    expect($second->supersedes_assessment_id)->toBe($first->id)
        ->and($second->gap)->toBe(0)
        ->and($second->result_band)->toBe(AssessmentResultBand::Meets)
        ->and($first->refresh()->assessed_level)->toBe(1);

    $score = EmployeeSkillScore::query()
        ->forCompany($tenantId, $companyEntityId)
        ->where('employee_entity_id', $employeeEntityId)
        ->where('skill_id', $skillId)
        ->sole();

    expect($score->current_level)->toBe(4)
        ->and($score->gap)->toBe(0)
        ->and($score->source_assessment_id)->toBe($second->id)
        ->and(SkillAssessment::query()->forCompany($tenantId, $companyEntityId)->count())->toBe(2);
});

test('a sibling company cannot finalize against this catalog or employee spine', function (): void {
    [$tenantId, $companyEntityId, $employeeEntityId, $skillId] = assessmentFixture();
    $sibling = NativeWorkforceFixture::create($tenantId, WorkforceResourceType::Company);

    expect(fn () => finalizeVerifiedAssessment(
        app(AssessmentStore::class),
        (int) $sibling->id,
        assessmentDraft($employeeEntityId, $skillId),
    ))->toThrow(InvalidAssessmentException::class);
});

test('submitBatch is atomic: one bad cell rolls back the whole matrix submission', function (): void {
    [$tenantId, $companyEntityId, $employeeEntityId, $skillId] = assessmentFixture();
    $store = app(AssessmentStore::class);

    $good = assessmentDraft($employeeEntityId, $skillId, ['assessedLevel' => 3]);
    $bad = assessmentDraft($employeeEntityId, $skillId, ['assessedLevel' => 3, 'evidence' => '']);

    expect(fn () => $store->submitBatch(assessmentActor(9), $companyEntityId, [$good, $bad]))
        ->toThrow(InvalidAssessmentException::class, 'Evidence');

    expect(SkillAssessment::query()->forCompany($tenantId, $companyEntityId)->count())->toBe(0)
        ->and(EmployeeSkillScore::query()->forCompany($tenantId, $companyEntityId)->count())->toBe(0);

    $saved = $store->submitBatch(assessmentActor(9), $companyEntityId, [
        assessmentDraft($employeeEntityId, $skillId, ['assessedLevel' => 3]),
    ]);

    $store->requestHodVerification(assessmentActor(9), $companyEntityId, (int) $saved[0]->id);
    $store->verifyHod(assessmentActor(10), $companyEntityId, (int) $saved[0]->id, 'Verified by the HOD.');
    $store->finalizeVerified(assessmentActor(10), $companyEntityId, (int) $saved[0]->id);

    expect($saved)->toHaveCount(1)
        ->and(SkillAssessment::query()->forCompany($tenantId, $companyEntityId)->count())->toBe(1);
});

test('a back-dated finalize does not regress the current-score projection', function (): void {
    [$tenantId, $companyEntityId, $employeeEntityId, $skillId] = assessmentFixture();
    $store = app(AssessmentStore::class);

    $newer = finalizeVerifiedAssessment($store, $companyEntityId, assessmentDraft($employeeEntityId, $skillId, [
        'assessedLevel' => 4,
        'assessedAt' => now()->subDays(1),
    ]));

    $older = finalizeVerifiedAssessment($store, $companyEntityId, assessmentDraft($employeeEntityId, $skillId, [
        'assessedLevel' => 1,
        'assessedAt' => now()->subDays(30),
    ]));

    $score = EmployeeSkillScore::query()
        ->forCompany($tenantId, $companyEntityId)
        ->where('employee_entity_id', $employeeEntityId)
        ->where('skill_id', $skillId)
        ->sole();

    expect($score->current_level)->toBe(4)
        ->and($score->source_assessment_id)->toBe($newer->id)
        ->and($score->source_assessment_id)->not->toBe($older->id)
        ->and(SkillAssessment::query()->forCompany($tenantId, $companyEntityId)->count())->toBe(2);
});

test('finalize matches department-scoped requirements from the employee projection', function (): void {
    assessmentWorkflowTestAudience();
    $tenant = createTenant(['name' => 'Scoped Assessment Tenant']);
    app(TenantContext::class)->set((int) $tenant->id);
    $tenantId = (int) $tenant->id;

    $company = NativeWorkforceFixture::create($tenantId, WorkforceResourceType::Company);
    $dept = NativeWorkforceFixture::create($tenantId, WorkforceResourceType::OrganizationUnit);
    $employee = NativeWorkforceFixture::create($tenantId, WorkforceResourceType::Employee);

    $category = app(SkillCatalogStore::class)->defineCategory((int) $company->id, 'ops', 'Operations');
    $skill = app(SkillCatalogStore::class)->defineSkill((int) $company->id, new SkillDraft(
        code: 'dept.skill',
        name: 'Dept Skill',
        definition: 'Department scoped.',
        categoryId: (int) $category->id,
        scope: SkillScope::Shared,
        criticalClassification: CriticalClassification::Quality,
        evidenceGuide: 'Evidence.',
        defaultAssessmentMethod: AssessmentMethod::DirectObservation,
        defaultReassessmentMonths: 12,
    ));
    app(SkillCatalogDefaults::class)->install((int) $company->id);

    $profiles = app(RequirementProfileStore::class);
    $draft = new RequirementProfileDraft(
        code: 'dept.scoped',
        name: 'Dept Scoped',
        selectors: [
            new RequirementSelectorDraft(SelectorType::Department, null, (int) $dept->id),
        ],
        items: [
            new RequirementItemDraft(
                skillId: (int) $skill->id,
                sequence: 1,
                requiredLevel: 3,
                criticality: RequirementCriticality::Essential,
                weightPercent: 100.0,
            ),
        ],
    );
    $profile = $profiles->draft((int) $company->id, $draft);
    $profiles->publish((int) $company->id, (int) $profile->id);

    // The R1 seam resolves subjects but does not enumerate employee relationships,
    // so the caller supplies the already-resolved department context.
    $assessment = finalizeVerifiedAssessment(
        app(AssessmentStore::class),
        (int) $company->id,
        assessmentDraft((int) $employee->id, (int) $skill->id, ['assessedLevel' => 2]),
        employeeData: ['department_entity_id' => (int) $dept->id],
    );

    expect($assessment->requirement_reference)->toBe('dept.scoped')
        ->and($assessment->required_level)->toBe(3)
        ->and($assessment->gap)->toBe(1);
});
