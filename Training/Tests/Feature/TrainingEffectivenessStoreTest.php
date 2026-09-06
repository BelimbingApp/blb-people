<?php

use App\Base\Authz\Enums\PrincipalType;
use App\Base\Authz\Models\PrincipalRole;
use App\Base\Authz\Models\Role;
use App\Base\Tenancy\Contracts\TenantContext;
use App\Core\Company\Models\Company;
use App\Core\Company\Models\Department;
use App\Core\Company\Models\DepartmentType;
use App\Core\Employee\Models\Employee;
use App\Core\User\Models\User;
use App\Domains\People\Settings\Models\EmployeePortalAccess;
use App\Domains\People\Settings\Models\EmployeeWorkProfile;
use App\Domains\People\Settings\Models\PeopleReferenceEntry;
use App\Domains\People\Skills\Contracts\ResolvesSkillRequirements;
use App\Domains\People\Skills\Data\AssessmentDraft;
use App\Domains\People\Skills\Data\RequirementItemDraft;
use App\Domains\People\Skills\Data\RequirementProfileDraft;
use App\Domains\People\Skills\Data\RequirementSelectorDraft;
use App\Domains\People\Skills\Data\ResolvedSkillRequirement;
use App\Domains\People\Skills\Data\SkillDraft;
use App\Domains\People\Skills\Enums\AssessmentCycle;
use App\Domains\People\Skills\Enums\AssessmentMethod;
use App\Domains\People\Skills\Enums\RequirementCriticality;
use App\Domains\People\Skills\Enums\SelectorType;
use App\Domains\People\Skills\Models\SkillAssessment;
use App\Domains\People\Skills\Services\AssessmentStore;
use App\Domains\People\Skills\Services\RequirementProfileStore;
use App\Domains\People\Skills\Services\SkillAudience;
use App\Domains\People\Skills\Services\SkillAudienceAssignmentStore;
use App\Domains\People\Skills\Services\SkillCatalogDefaults;
use App\Domains\People\Skills\Services\SkillCatalogStore;
use App\Domains\People\Training\Data\EffectivenessOutcomeDraft;
use App\Domains\People\Training\Data\EffectivenessReviewDraft;
use App\Domains\People\Training\Data\TrainingCourseDraft;
use App\Domains\People\Training\Data\TrainingEventDraft;
use App\Domains\People\Training\Enums\DeliveryMode;
use App\Domains\People\Training\Enums\EffectivenessClosureRoute;
use App\Domains\People\Training\Enums\EffectivenessOutcome;
use App\Domains\People\Training\Enums\EffectivenessReviewStage;
use App\Domains\People\Training\Enums\EffectivenessReviewState;
use App\Domains\People\Training\Exceptions\InvalidEffectivenessReviewException;
use App\Domains\People\Training\Models\TrainingEffectivenessReview;
use App\Domains\People\Training\Models\TrainingParticipant;
use App\Domains\People\Training\Services\TrainingCatalogStore;
use App\Domains\People\Training\Services\TrainingEffectivenessStore;
use App\Domains\People\Training\Services\TrainingEventStore;
use Illuminate\Support\Facades\DB;

afterEach(function (): void {
    app(TenantContext::class)->clear();
});

/**
 * Self-contained: Pest does not load helpers from sibling test files when one
 * file is run on its own.
 *
 * @return array<string, mixed>
 */
function effFixture(): array
{
    [$tenant, $company] = createTenantWithCompany();
    $tenantId = (int) $tenant->id;
    $companyId = (int) $company->id;
    app(TenantContext::class)->set($tenantId);

    $entry = PeopleReferenceEntry::query()->create([
        'company_id' => $companyId, 'type' => PeopleReferenceEntry::TYPE_ORGANIZATION_UNIT,
        'code' => 'OPS-EFF', 'name' => 'Operations effectiveness', 'status' => PeopleReferenceEntry::STATUS_ACTIVE,
    ]);
    $type = DepartmentType::query()->create([
        'code' => 'ops-eff', 'name' => 'Operations effectiveness', 'category' => 'operational', 'is_active' => true,
    ]);
    $department = Department::query()->create([
        'company_id' => $companyId, 'department_type_id' => $type->id, 'status' => 'active',
    ]);
    $head = Employee::factory()->create([
        'company_id' => $companyId, 'department_id' => $department->id,
        'full_name' => 'Effectiveness HOD', 'status' => 'active', 'employee_type' => 'full_time',
    ]);
    $department->update(['head_id' => $head->id]);
    EmployeeWorkProfile::query()->create(['employee_id' => $head->id, 'organization_unit_id' => $entry->id]);
    $learner = Employee::factory()->create([
        'company_id' => $companyId, 'department_id' => $department->id, 'supervisor_id' => $head->id,
        'full_name' => 'Effectiveness Learner', 'status' => 'active', 'employee_type' => 'full_time',
    ]);
    EmployeeWorkProfile::query()->create(['employee_id' => $learner->id, 'organization_unit_id' => $entry->id]);

    $hr = User::factory()->create(['company_id' => $companyId]);
    $hod = User::factory()->create(['company_id' => $companyId, 'employee_id' => $head->id]);
    $nobody = User::factory()->create(['company_id' => $companyId]);
    EmployeePortalAccess::query()->create([
        'employee_id' => $head->id, 'user_id' => $hod->id,
        'display_name' => 'Effectiveness HOD', 'status' => EmployeePortalAccess::STATUS_ACTIVE,
    ]);
    setupAuthzRoles();
    foreach ([[$hr, 'people_hr'], [$hod, 'people_hod']] as [$actor, $code]) {
        $role = Role::query()->whereNull('company_id')->where('code', $code)->sole();
        PrincipalRole::query()->create([
            'company_id' => $companyId, 'principal_type' => PrincipalType::USER->value,
            'principal_id' => $actor->id, 'role_id' => $role->id,
        ]);
    }
    app(SkillAudienceAssignmentStore::class)->confirmActor(
        $hr, $hod, $companyId, (int) $head->id, 'review:effectiveness-hod',
    );

    $category = app(SkillCatalogStore::class)->defineCategory($companyId, 'safety', 'Safety');
    $skill = app(SkillCatalogStore::class)->defineSkill($companyId, new SkillDraft(
        code: 'isolation.energy', name: 'Energy isolation',
        definition: 'Isolate stored energy before maintenance.',
        categoryId: (int) $category->id, defaultAssessmentMethod: AssessmentMethod::DirectObservation,
    ));
    app(SkillCatalogDefaults::class)->install($companyId);

    $profileStore = app(RequirementProfileStore::class);
    $profile = $profileStore->draft($companyId, new RequirementProfileDraft(
        code: 'fixture.isolation',
        name: 'Fixture Isolation',
        selectors: [new RequirementSelectorDraft(SelectorType::Company)],
        items: [new RequirementItemDraft(
            skillId: (int) $skill->id, sequence: 1, requiredLevel: 4,
            criticality: RequirementCriticality::Critical, weightPercent: 100.0,
        )],
    ));
    $profile = $profileStore->publish($companyId, (int) $profile->id);
    app()->instance(ResolvesSkillRequirements::class, new EffFixtureRequirements([
        new ResolvedSkillRequirement(
            requirementReference: 'fixture.isolation', requirementVersion: 3,
            requirementProfileId: (int) $profile->id, skillId: (int) $skill->id,
            requiredLevel: 4, criticality: RequirementCriticality::Critical, mandatoryGate: true,
        ),
    ]));

    $course = app(TrainingCatalogStore::class)->defineCourse($companyId, new TrainingCourseDraft(
        code: 'isolation.induction', title: 'Isolation induction',
        deliveryMode: DeliveryMode::InternalClassroom, skillIds: [(int) $skill->id],
        internalTrainerEmployeeEntityId: (int) $head->id,
    ));
    $event = app(TrainingEventStore::class)->schedule($companyId, new TrainingEventDraft(
        courseId: (int) $course->id,
        startsAt: new DateTimeImmutable('2027-03-01T09:00:00+00:00'),
        endsAt: new DateTimeImmutable('2027-03-01T17:00:00+00:00'),
        capacity: 10, organizerEmployeeEntityId: (int) $head->id,
        targetDepartmentEntityId: (int) $entry->id,
    ));
    $participant = TrainingParticipant::query()->create([
        'tenant_id' => $tenantId, 'company_entity_id' => $companyId, 'event_id' => $event->id,
        'provider_id' => 'native', 'employee_subject_id' => (string) $learner->id,
        'workforce_observed_at' => now(),
    ]);

    return compact('tenant', 'company', 'tenantId', 'companyId', 'entry',
        'head', 'learner', 'hr', 'hod', 'nobody', 'skill', 'course', 'event', 'participant');
}

/**
 * The workflow authority that writes assessment rows directly is private to
 * Skills and its own tests, and rightly so. A Training test has to build its
 * reassessment the way production does: submit, request verification, verify,
 * finalize. The audience stub covers only the Skills assessment gates for the
 * duration of that build and is dropped immediately after, so every assertion
 * about *this* store still runs against the real SkillAudience.
 */
final class EffFixtureRequirements implements ResolvesSkillRequirements
{
    /** @param list<ResolvedSkillRequirement> $rows */
    public function __construct(private array $rows) {}

    public function requirementsFor(array $employeeData, ?DateTimeInterface $asOf = null): array
    {
        return $this->rows;
    }
}

function effStubAssessmentAudience(): void
{
    app()->instance(SkillAudience::class, new class extends SkillAudience
    {
        public function __construct() {}

        public function authorizeAssessmentSubmission(User $user, int $companyEntityId, int $employeeEntityId): void {}

        public function authorizeHodVerification(User $user, int $companyEntityId, int $employeeEntityId): void {}

        public function authorizeAssessmentFinalization(User $user, int $companyEntityId, int $employeeEntityId): void {}
    });
}

function effRestoreRealAudience(): void
{
    app()->forgetInstance(SkillAudience::class);
    app()->forgetInstance(TrainingEffectivenessStore::class);
}

function effAssessmentDraft(array $f, int $employeeEntityId, int $level): AssessmentDraft
{
    return new AssessmentDraft(
        employeeEntityId: $employeeEntityId,
        skillId: (int) $f['skill']->id,
        assessedLevel: $level,
        method: AssessmentMethod::DirectObservation,
        cycle: AssessmentCycle::Annual,
        assessedAt: now()->subDay(),
        evidence: 'Observed two compliant isolation cycles.',
        assessorUserId: 9,
        weightPercent: 100.0,
    );
}

function effFinalizedAssessment(array $f, int $employeeEntityId, int $level = 4): SkillAssessment
{
    effStubAssessmentAudience();
    $store = app(AssessmentStore::class);
    try {
        $actor = User::factory()->make(['id' => 9]);
        $verifier = User::factory()->make(['id' => 10]);
        $submitted = $store->submit($actor, $f['companyId'], effAssessmentDraft($f, $employeeEntityId, $level));
        $pending = $store->requestHodVerification($actor, $f['companyId'], (int) $submitted->id);
        $store->verifyHod($verifier, $f['companyId'], (int) $pending->id, 'Verified against the submitted evidence.');

        return $store->finalizeVerified($verifier, $f['companyId'], (int) $pending->id);
    } finally {
        effRestoreRealAudience();
    }
}

function effDraftAssessment(array $f, int $employeeEntityId, int $level = 4): SkillAssessment
{
    effStubAssessmentAudience();
    try {
        return app(AssessmentStore::class)->draft($f['companyId'], effAssessmentDraft($f, $employeeEntityId, $level));
    } finally {
        effRestoreRealAudience();
    }
}

function effReviewDraft(array $f, array $overrides = []): EffectivenessReviewDraft
{
    return new EffectivenessReviewDraft(...array_replace([
        'participantId' => (int) $f['participant']->id,
        'stage' => EffectivenessReviewStage::Day30,
        'dueOn' => new DateTimeImmutable('2027-03-31'),
        'dueDatePolicy' => 'policy:0013 thirty days after the recorded return to work',
        'reviewerEmployeeEntityId' => (int) $f['head']->id,
        'baselineLevel' => 2,
        'targetLevel' => 4,
        'requirementReference' => 'fixture.isolation',
        'requirementVersion' => 3,
    ], $overrides));
}

function effOutcomeDraft(array $overrides = []): EffectivenessOutcomeDraft
{
    return new EffectivenessOutcomeDraft(...array_replace([
        'outcome' => EffectivenessOutcome::Effective,
        'applicationRating' => 4,
        'improvementRating' => 4,
        'impactRating' => 3,
        'evidence' => 'Two supervised isolations signed off on the maintenance log.',
        'reviewedOn' => new DateTimeImmutable('2027-03-28'),
    ], $overrides));
}

function effOpen(array $f, array $overrides = []): TrainingEffectivenessReview
{
    return app(TrainingEffectivenessStore::class)
        ->openStage($f['hod'], $f['companyId'], effReviewDraft($f, $overrides));
}

test('a HOD opens a stage and repeating it records another occurrence rather than overwriting', function (): void {
    $f = effFixture();
    $first = effOpen($f);
    $second = effOpen($f);

    expect($first->id)->not->toBe($second->id)
        ->and($first->stage)->toBe(EffectivenessReviewStage::Day30)
        ->and($first->state)->toBe(EffectivenessReviewState::Open)
        ->and($first->due_date_policy)->toContain('policy:0013')
        ->and(TrainingEffectivenessReview::query()
            ->forCompany($f['tenantId'], $f['companyId'])
            ->where('training_participant_id', $f['participant']->id)
            ->where('stage', EffectivenessReviewStage::Day30->value)->count())->toBe(2);
});

test('an unknown baseline or target is stored as unknown, never as zero', function (): void {
    $f = effFixture();
    $review = effOpen($f, ['baselineLevel' => null, 'targetLevel' => null]);

    expect($review->baseline_level)->toBeNull()
        ->and($review->target_level)->toBeNull();
});

test('the four retained stages are available, including Final', function (): void {
    $f = effFixture();
    foreach ([EffectivenessReviewStage::Day30, EffectivenessReviewStage::Day60,
        EffectivenessReviewStage::Day90, EffectivenessReviewStage::Final] as $stage) {
        expect(effOpen($f, ['stage' => $stage])->stage)->toBe($stage);
    }
});

test('opening a stage without the review capability is refused', function (): void {
    $f = effFixture();

    expect(fn () => app(TrainingEffectivenessStore::class)
        ->openStage($f['nobody'], $f['companyId'], effReviewDraft($f)))
        ->toThrow(InvalidEffectivenessReviewException::class, 'review');
});

test('a due date needs the governed policy that chose it', function (): void {
    $f = effFixture();

    expect(fn () => effOpen($f, ['dueDatePolicy' => '   ']))
        ->toThrow(InvalidEffectivenessReviewException::class, 'policy');
});

test('recording an outcome without the review capability is refused', function (): void {
    $f = effFixture();
    $review = effOpen($f);

    expect(fn () => app(TrainingEffectivenessStore::class)
        ->recordOutcome($f['nobody'], $f['companyId'], (int) $review->id, effOutcomeDraft()))
        ->toThrow(InvalidEffectivenessReviewException::class, 'review');
});

test('a workplace rating outside the one-to-five scale is refused', function (): void {
    $f = effFixture();
    $store = app(TrainingEffectivenessStore::class);
    foreach ([['applicationRating' => 0], ['improvementRating' => 6], ['impactRating' => -1]] as $bad) {
        $review = effOpen($f);
        expect(fn () => $store->recordOutcome($f['hod'], $f['companyId'], (int) $review->id, effOutcomeDraft($bad)))
            ->toThrow(InvalidEffectivenessReviewException::class, 'between 1 and 5');
    }
});

test('an outcome cannot be recorded on a closed review', function (): void {
    $f = effFixture();
    $store = app(TrainingEffectivenessStore::class);
    $review = effOpen($f);
    $store->recordOutcome($f['hod'], $f['companyId'], (int) $review->id, effOutcomeDraft());
    $store->closeAsNonAssessable($f['hr'], $f['companyId'], (int) $review->id,
        'Statutory awareness briefing with no assessable skill target.');

    expect(fn () => $store->recordOutcome($f['hod'], $f['companyId'], (int) $review->id, effOutcomeDraft()))
        ->toThrow(InvalidEffectivenessReviewException::class, 'closed');
});

test('closing needs HR, not the HOD who recorded the outcome', function (): void {
    $f = effFixture();
    $store = app(TrainingEffectivenessStore::class);
    $review = effOpen($f);
    $store->recordOutcome($f['hod'], $f['companyId'], (int) $review->id, effOutcomeDraft());

    expect(fn () => $store->closeAsNonAssessable($f['hod'], $f['companyId'], (int) $review->id, 'Not assessable.'))
        ->toThrow(InvalidEffectivenessReviewException::class, 'close');
});

test('a recorded outcome is not by itself permission to close', function (): void {
    $f = effFixture();
    $store = app(TrainingEffectivenessStore::class);
    $review = effOpen($f);
    $assessment = effFinalizedAssessment($f, (int) $f['learner']->id);

    expect(fn () => $store->closeWithReassessment($f['hr'], $f['companyId'], (int) $review->id, (int) $assessment->id))
        ->toThrow(InvalidEffectivenessReviewException::class, 'outcome');
});

test('closing with a reassessment refuses an assessment that is not finalized', function (): void {
    $f = effFixture();
    $store = app(TrainingEffectivenessStore::class);
    $review = effOpen($f);
    $store->recordOutcome($f['hod'], $f['companyId'], (int) $review->id, effOutcomeDraft());
    $draft = effDraftAssessment($f, (int) $f['learner']->id);

    expect(fn () => $store->closeWithReassessment($f['hr'], $f['companyId'], (int) $review->id, (int) $draft->id))
        ->toThrow(InvalidEffectivenessReviewException::class, 'finalized');
});

test('closing with a reassessment refuses an assessment belonging to another employee', function (): void {
    $f = effFixture();
    $store = app(TrainingEffectivenessStore::class);
    $review = effOpen($f);
    $store->recordOutcome($f['hod'], $f['companyId'], (int) $review->id, effOutcomeDraft());
    $someoneElse = effFinalizedAssessment($f, (int) $f['head']->id);

    expect(fn () => $store->closeWithReassessment($f['hr'], $f['companyId'], (int) $review->id, (int) $someoneElse->id))
        ->toThrow(InvalidEffectivenessReviewException::class, 'participant');
});

test('a verified reassessment closes the review and pins the requirement version it was measured against', function (): void {
    $f = effFixture();
    $store = app(TrainingEffectivenessStore::class);
    $review = effOpen($f);
    $store->recordOutcome($f['hod'], $f['companyId'], (int) $review->id, effOutcomeDraft());
    $assessment = effFinalizedAssessment($f, (int) $f['learner']->id);
    $closed = $store->closeWithReassessment($f['hr'], $f['companyId'], (int) $review->id, (int) $assessment->id);

    expect($closed->state)->toBe(EffectivenessReviewState::Closed)
        ->and($closed->closure_route)->toBe(EffectivenessClosureRoute::Reassessment)
        ->and((int) $closed->reassessment_assessment_id)->toBe((int) $assessment->id)
        ->and($closed->reassessment_requirement_reference)->toBe('fixture.isolation')
        ->and((int) $closed->reassessment_requirement_version)->toBe(3)
        ->and((int) $closed->post_level)->toBe(4);
});

test('a non-assessable closure without a reason is refused, and a reasoned one stays distinguishable', function (): void {
    $f = effFixture();
    $store = app(TrainingEffectivenessStore::class);
    $review = effOpen($f);
    $store->recordOutcome($f['hod'], $f['companyId'], (int) $review->id,
        effOutcomeDraft(['outcome' => EffectivenessOutcome::NotApplicable]));

    expect(fn () => $store->closeAsNonAssessable($f['hr'], $f['companyId'], (int) $review->id, '   '))
        ->toThrow(InvalidEffectivenessReviewException::class, 'reason');

    $closed = $store->closeAsNonAssessable($f['hr'], $f['companyId'], (int) $review->id,
        'Statutory fire-safety briefing; HR confirmed no assessable skill target.');

    expect($closed->closure_route)->toBe(EffectivenessClosureRoute::NonAssessable)
        ->and($closed->reassessment_assessment_id)->toBeNull()
        ->and($closed->post_level)->toBeNull();
});

test('a review in another company is not reachable from this one', function (): void {
    $f = effFixture();
    $review = effOpen($f);
    $other = Company::factory()->create(['tenant_id' => $f['tenantId'], 'name' => 'Other', 'status' => 'active']);

    expect(fn () => app(TrainingEffectivenessStore::class)
        ->recordOutcome($f['hod'], (int) $other->id, (int) $review->id, effOutcomeDraft()))
        ->toThrow(InvalidEffectivenessReviewException::class);
});

test('effectiveness reviews need a tenant context', function (): void {
    $f = effFixture();
    $draft = effReviewDraft($f);
    app(TenantContext::class)->clear();

    expect(fn () => app(TrainingEffectivenessStore::class)->openStage($f['hod'], $f['companyId'], $draft))
        ->toThrow(InvalidEffectivenessReviewException::class, 'tenant');
});

test('an outcome needs attributable workplace evidence', function (): void {
    $f = effFixture();
    $review = effOpen($f);

    expect(fn () => app(TrainingEffectivenessStore::class)
        ->recordOutcome($f['hod'], $f['companyId'], (int) $review->id, effOutcomeDraft(['evidence' => '   '])))
        ->toThrow(InvalidEffectivenessReviewException::class, 'evidence');
});

test('a HOD of another company cannot act on this company\'s review', function (): void {
    $f = effFixture();
    $review = effOpen($f);
    $other = Company::factory()->create(['tenant_id' => $f['tenantId'], 'name' => 'Neighbour', 'status' => 'active']);
    $outsider = User::factory()->create(['company_id' => $other->id]);
    $role = Role::query()->whereNull('company_id')->where('code', 'people_hod')->sole();
    PrincipalRole::query()->create([
        'company_id' => $other->id, 'principal_type' => PrincipalType::USER->value,
        'principal_id' => $outsider->id, 'role_id' => $role->id,
    ]);

    // The review id and the company id are both this company's; the only thing
    // between the outsider and the record is the company attribution check.
    expect(fn () => app(TrainingEffectivenessStore::class)
        ->recordOutcome($outsider, $f['companyId'], (int) $review->id, effOutcomeDraft()))
        ->toThrow(InvalidEffectivenessReviewException::class, 'company scope');
});

test('separation of duties survives a capability granted to the wrong role', function (): void {
    $f = effFixture();
    $store = app(TrainingEffectivenessStore::class);
    $review = effOpen($f);
    $store->recordOutcome($f['hod'], $f['companyId'], (int) $review->id, effOutcomeDraft());

    // Simulate the authz config drifting: an assessor is granted the closure
    // capability. The audience is the second lock, so closure still refuses.
    $assessorRole = Role::query()->whereNull('company_id')->where('code', 'people_assessor')->sole();
    DB::table('base_authz_role_capabilities')->insertOrIgnore([
        'role_id' => $assessorRole->id,
        'capability_key' => TrainingEffectivenessStore::CLOSE_CAPABILITY,
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $assessor = User::factory()->create(['company_id' => $f['companyId']]);
    PrincipalRole::query()->create([
        'company_id' => $f['companyId'], 'principal_type' => PrincipalType::USER->value,
        'principal_id' => $assessor->id, 'role_id' => $assessorRole->id,
    ]);

    expect(fn () => $store->closeAsNonAssessable($assessor, $f['companyId'], (int) $review->id, 'Not assessable.'))
        ->toThrow(InvalidEffectivenessReviewException::class, 'Only HR');
});
