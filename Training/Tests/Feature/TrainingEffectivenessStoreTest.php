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
use App\Domains\People\Skills\Data\DevelopmentActionDraft;
use App\Domains\People\Skills\Data\RequirementItemDraft;
use App\Domains\People\Skills\Data\RequirementProfileDraft;
use App\Domains\People\Skills\Data\RequirementSelectorDraft;
use App\Domains\People\Skills\Data\ResolvedSkillRequirement;
use App\Domains\People\Skills\Data\SkillDraft;
use App\Domains\People\Skills\Enums\AssessmentCycle;
use App\Domains\People\Skills\Enums\AssessmentMethod;
use App\Domains\People\Skills\Enums\DevelopmentActionClosure;
use App\Domains\People\Skills\Enums\DevelopmentActionStatus;
use App\Domains\People\Skills\Enums\DevelopmentActionType;
use App\Domains\People\Skills\Enums\RequirementCriticality;
use App\Domains\People\Skills\Enums\SelectorType;
use App\Domains\People\Skills\Models\DevelopmentAction;
use App\Domains\People\Skills\Models\DevelopmentActionAuditEvent;
use App\Domains\People\Skills\Models\SkillAssessment;
use App\Domains\People\Skills\Services\AssessmentStore;
use App\Domains\People\Skills\Services\DevelopmentActionStore;
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
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

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

function effAssessmentDraft(array $f, int $employeeEntityId, int $level, ?int $assessorEmployeeEntityId = null): AssessmentDraft
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
        assessorEmployeeEntityId: $assessorEmployeeEntityId,
        weightPercent: 100.0,
    );
}

function effFinalizedAssessment(
    array $f,
    int $employeeEntityId,
    int $level = 4,
    ?int $assessorEmployeeEntityId = null,
): SkillAssessment {
    effStubAssessmentAudience();
    $store = app(AssessmentStore::class);
    try {
        $actor = User::factory()->make(['id' => 9]);
        $verifier = User::factory()->make(['id' => 10]);
        $submitted = $store->submit($actor, $f['companyId'],
            effAssessmentDraft($f, $employeeEntityId, $level, $assessorEmployeeEntityId));
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

/**
 * A participant row for an employee other than the fixture's learner, so a
 * conflict case can put the acting HOD's own training under review.
 */
function effParticipantFor(array $f, int $employeeEntityId): TrainingParticipant
{
    return TrainingParticipant::query()->create([
        'tenant_id' => $f['tenantId'], 'company_entity_id' => $f['companyId'],
        'event_id' => $f['event']->id, 'provider_id' => 'native',
        'employee_subject_id' => (string) $employeeEntityId,
        'workforce_observed_at' => now(),
    ]);
}

/** @param array<string, mixed> $overrides */
function effReviewRow(array $f, TrainingParticipant $participant, int $reviewerEmployeeEntityId, array $overrides = []): TrainingEffectivenessReview
{
    return TrainingEffectivenessReview::query()->create(array_replace([
        'tenant_id' => $f['tenantId'], 'company_entity_id' => $f['companyId'],
        'training_participant_id' => (int) $participant->id,
        'stage' => EffectivenessReviewStage::Day30,
        'due_on' => '2027-03-31', 'due_date_policy' => 'policy:0013 thirty days after the recorded return to work',
        'reviewer_employee_entity_id' => $reviewerEmployeeEntityId,
        'state' => EffectivenessReviewState::Open,
    ], $overrides));
}

test('a stage cannot name the reviewed participant as its own reviewer', function (): void {
    $f = effFixture();

    expect(fn () => effOpen($f, ['reviewerEmployeeEntityId' => (int) $f['learner']->id]))
        ->toThrow(InvalidEffectivenessReviewException::class, 'participant under review');
});

test('the database refuses a review row whose reviewer is the reviewed participant', function (): void {
    $f = effFixture();

    // The store is not the only write path. Each violating write gets its own
    // transaction so an aborted statement cannot poison the surrounding one.
    $insert = fn (int $reviewer): bool => DB::transaction(fn (): bool => DB::table('people_training_effectiveness_reviews')->insert([
        'tenant_id' => $f['tenantId'], 'company_entity_id' => $f['companyId'],
        'training_participant_id' => (int) $f['participant']->id,
        'stage' => EffectivenessReviewStage::Day30->value,
        'due_on' => '2027-03-31', 'due_date_policy' => 'raw insert',
        'reviewer_employee_entity_id' => $reviewer,
        'state' => EffectivenessReviewState::Open->value,
        'created_at' => now(), 'updated_at' => now(),
    ]));

    expect(fn () => $insert((int) $f['learner']->id))
        ->toThrow(QueryException::class, 'reviewer cannot be the reviewed participant');

    // Control: the same raw insert with an independent reviewer is accepted,
    // so the guard refuses the conflict and not the write path.
    expect($insert((int) $f['head']->id))->toBeTrue();

    $stored = TrainingEffectivenessReview::query()->forCompany($f['tenantId'], $f['companyId'])
        ->where('due_date_policy', 'raw insert')->sole();

    expect(fn () => DB::transaction(fn () => DB::table('people_training_effectiveness_reviews')
        ->where('id', $stored->id)
        ->update(['reviewer_employee_entity_id' => (int) $f['learner']->id])))
        ->toThrow(QueryException::class, 'reviewer cannot be the reviewed participant');
});

test('a HOD cannot open a stage on their own training', function (): void {
    $f = effFixture();
    $own = effParticipantFor($f, (int) $f['head']->id);

    expect(fn () => effOpen($f, [
        'participantId' => (int) $own->id,
        'reviewerEmployeeEntityId' => (int) $f['learner']->id,
    ]))->toThrow(InvalidEffectivenessReviewException::class, 'your own training');
});

test('a HOD cannot record an outcome on their own training but can on a direct report\'s', function (): void {
    $f = effFixture();
    $store = app(TrainingEffectivenessStore::class);
    $own = effReviewRow($f, effParticipantFor($f, (int) $f['head']->id), (int) $f['learner']->id);

    expect(fn () => $store->recordOutcome($f['hod'], $f['companyId'], (int) $own->id, effOutcomeDraft()))
        ->toThrow(InvalidEffectivenessReviewException::class, 'your own training');

    $report = $store->recordOutcome($f['hod'], $f['companyId'], (int) effOpen($f)->id, effOutcomeDraft());

    expect($report->state)->toBe(EffectivenessReviewState::OutcomeRecorded);
});

test('neither closure route lets an actor close their own training review', function (): void {
    $f = effFixture();
    $store = app(TrainingEffectivenessStore::class);
    $hrEmployee = Employee::factory()->create([
        'company_id' => $f['companyId'], 'status' => 'active', 'employee_type' => 'full_time',
        'full_name' => 'Effectiveness HR',
    ]);
    EmployeePortalAccess::query()->create([
        'employee_id' => $hrEmployee->id, 'user_id' => $f['hr']->id,
        'display_name' => 'Effectiveness HR', 'status' => EmployeePortalAccess::STATUS_ACTIVE,
    ]);
    // The seam reads the projected link from both sides: an active portal
    // access row and the user's own employee_id.
    $f['hr']->forceFill(['employee_id' => $hrEmployee->id])->save();
    $own = effReviewRow($f, effParticipantFor($f, (int) $hrEmployee->id), (int) $f['head']->id, [
        'state' => EffectivenessReviewState::OutcomeRecorded,
    ]);
    $assessment = effFinalizedAssessment($f, (int) $hrEmployee->id);

    expect(fn () => $store->closeAsNonAssessable($f['hr'], $f['companyId'], (int) $own->id, 'No assessable target.'))
        ->toThrow(InvalidEffectivenessReviewException::class, 'your own training');

    expect(fn () => $store->closeWithReassessment($f['hr'], $f['companyId'], (int) $own->id, (int) $assessment->id))
        ->toThrow(InvalidEffectivenessReviewException::class, 'your own training');
});

test('closing refuses a reassessment made by the review\'s own reviewer and accepts an independent one', function (): void {
    $f = effFixture();
    $store = app(TrainingEffectivenessStore::class);
    $conflicted = effOpen($f);
    $store->recordOutcome($f['hod'], $f['companyId'], (int) $conflicted->id, effOutcomeDraft());
    $byTheReviewer = effFinalizedAssessment($f, (int) $f['learner']->id, 4, (int) $f['head']->id);

    expect(fn () => $store->closeWithReassessment($f['hr'], $f['companyId'], (int) $conflicted->id, (int) $byTheReviewer->id))
        ->toThrow(InvalidEffectivenessReviewException::class, 'assessor/HOD separation');

    $independentAssessor = Employee::factory()->create([
        'company_id' => $f['companyId'], 'status' => 'active', 'employee_type' => 'full_time',
        'full_name' => 'Independent assessor',
    ]);
    $clean = effOpen($f);
    $store->recordOutcome($f['hod'], $f['companyId'], (int) $clean->id, effOutcomeDraft());
    $independent = effFinalizedAssessment($f, (int) $f['learner']->id, 4, (int) $independentAssessor->id);
    $closed = $store->closeWithReassessment($f['hr'], $f['companyId'], (int) $clean->id, (int) $independent->id);

    expect($closed->state)->toBe(EffectivenessReviewState::Closed)
        ->and($closed->closure_route)->toBe(EffectivenessClosureRoute::Reassessment);
});

test('a reviewer from a sibling company in the same tenant is refused, and another tenant cannot reach the review', function (): void {
    $f = effFixture();
    $sibling = Company::factory()->create([
        'tenant_id' => $f['tenantId'], 'name' => 'Sibling Works', 'status' => 'active',
    ]);
    $siblingEmployee = Employee::factory()->create([
        'company_id' => $sibling->id, 'status' => 'active', 'employee_type' => 'full_time',
        'full_name' => 'Sibling reviewer',
    ]);

    expect(fn () => effOpen($f, ['reviewerEmployeeEntityId' => (int) $siblingEmployee->id]))
        ->toThrow(InvalidEffectivenessReviewException::class, 'reviewer from this company');

    $review = effOpen($f);
    [, $foreignCompany] = createTenantWithCompany([], ['name' => 'Foreign Co', 'status' => 'active']);
    $outsider = User::factory()->create(['company_id' => $foreignCompany->id]);

    expect(fn () => app(TrainingEffectivenessStore::class)
        ->recordOutcome($outsider, $f['companyId'], (int) $review->id, effOutcomeDraft()))
        ->toThrow(InvalidEffectivenessReviewException::class, 'company scope');
});

function effFollowUpDraft(array $f, array $overrides = []): DevelopmentActionDraft
{
    return new DevelopmentActionDraft(...array_replace([
        'employeeEntityId' => (int) $f['learner']->id,
        'type' => DevelopmentActionType::Coaching,
        'objective' => 'Reach the isolation level the course was meant to deliver.',
        'intervention' => 'Weekly coached isolations with the shift lead.',
        'expectedEvidence' => 'Three signed isolation permits without a correction.',
        'ownerEmployeeEntityId' => (int) $f['head']->id,
        'hrCoordinatorEmployeeEntityId' => (int) $f['head']->id,
        'startDate' => new DateTimeImmutable('2027-04-01'),
        'dueDate' => new DateTimeImmutable('2027-05-01'),
        'trainerEmployeeEntityId' => (int) $f['head']->id,
        'criticality' => RequirementCriticality::Critical,
    ], $overrides));
}

/** A review that has recorded an outcome the follow-up rule cares about. */
function effReviewed(array $f, EffectivenessOutcome $outcome): TrainingEffectivenessReview
{
    $review = effOpen($f);

    return app(TrainingEffectivenessStore::class)->recordOutcome(
        $f['hod'], $f['companyId'], (int) $review->id, effOutcomeDraft(['outcome' => $outcome]),
    );
}

test('an effective outcome owes no follow-up action and opening one is refused', function (): void {
    $f = effFixture();
    $review = effReviewed($f, EffectivenessOutcome::Effective);

    expect(fn () => app(TrainingEffectivenessStore::class)
        ->openFollowUpAction($f['hod'], $f['companyId'], (int) $review->id, effFollowUpDraft($f)))
        ->toThrow(InvalidEffectivenessReviewException::class, 'owes a follow-up')
        ->and(DevelopmentAction::query()->forCompany($f['tenantId'], $f['companyId'])->count())->toBe(0);
});

test('a not-yet-effective outcome opens one action carrying the review employee, skill and target level', function (): void {
    $f = effFixture();
    $review = effReviewed($f, EffectivenessOutcome::NotYetEffective);

    $linked = app(TrainingEffectivenessStore::class)
        ->openFollowUpAction($f['hod'], $f['companyId'], (int) $review->id, effFollowUpDraft($f));

    $action = DevelopmentAction::query()->forCompany($f['tenantId'], $f['companyId'])->sole();

    expect((int) $linked->development_action_id)->toBe((int) $action->id)
        ->and((int) $action->employee_entity_id)->toBe((int) $f['learner']->id)
        ->and((int) $action->skill_id)->toBe((int) $f['skill']->id)
        ->and((int) $action->target_level)->toBe(4)
        ->and((int) $action->starting_level)->toBe(2)
        ->and((string) $action->manual_reason)->toContain('review #'.$review->id)
        ->and(DevelopmentActionAuditEvent::query()->forCompany($f['tenantId'], $f['companyId'])
            ->where('development_action_id', $action->id)->count())->toBe(1);
});

test('the starting level is the verified post-training level when the review has one', function (): void {
    $f = effFixture();
    $review = effReviewed($f, EffectivenessOutcome::PartiallyEffective);
    $review->forceFill(['post_level' => 3])->save();

    app(TrainingEffectivenessStore::class)
        ->openFollowUpAction($f['hod'], $f['companyId'], (int) $review->id, effFollowUpDraft($f));

    expect((int) DevelopmentAction::query()->forCompany($f['tenantId'], $f['companyId'])->sole()->starting_level)->toBe(3);
});

test('a review carries one follow-up action and a second call is refused', function (): void {
    $f = effFixture();
    $review = effReviewed($f, EffectivenessOutcome::NotYetEffective);
    $store = app(TrainingEffectivenessStore::class);
    $store->openFollowUpAction($f['hod'], $f['companyId'], (int) $review->id, effFollowUpDraft($f));

    expect(fn () => $store->openFollowUpAction($f['hod'], $f['companyId'], (int) $review->id, effFollowUpDraft($f)))
        ->toThrow(InvalidEffectivenessReviewException::class, 'already carries a follow-up')
        ->and(DevelopmentAction::query()->forCompany($f['tenantId'], $f['companyId'])->count())->toBe(1);
});

test('a closed review refuses a follow-up action', function (): void {
    $f = effFixture();
    $review = effReviewed($f, EffectivenessOutcome::NotYetEffective);
    app(TrainingEffectivenessStore::class)
        ->closeAsNonAssessable($f['hr'], $f['companyId'], (int) $review->id, 'The learner left the company.');

    expect(fn () => app(TrainingEffectivenessStore::class)
        ->openFollowUpAction($f['hod'], $f['companyId'], (int) $review->id, effFollowUpDraft($f)))
        ->toThrow(InvalidEffectivenessReviewException::class, 'A closed review is a historical fact')
        ->and(DevelopmentAction::query()->forCompany($f['tenantId'], $f['companyId'])->count())->toBe(0);
});

test('a follow-up addresses only a skill the reviewed course covers', function (): void {
    $f = effFixture();
    $other = app(SkillCatalogStore::class)->defineSkill($f['companyId'], new SkillDraft(
        code: 'forklift.basic', name: 'Forklift basics',
        definition: 'Operate a counterbalance forklift.',
        categoryId: (int) app(SkillCatalogStore::class)->defineCategory($f['companyId'], 'plant', 'Plant')->id,
        defaultAssessmentMethod: AssessmentMethod::DirectObservation,
    ));
    $review = effReviewed($f, EffectivenessOutcome::NotYetEffective);

    expect(fn () => app(TrainingEffectivenessStore::class)->openFollowUpAction(
        $f['hod'], $f['companyId'], (int) $review->id, effFollowUpDraft($f, ['skillId' => (int) $other->id]),
    ))->toThrow(InvalidEffectivenessReviewException::class, 'does not cover')
        ->and(DevelopmentAction::query()->forCompany($f['tenantId'], $f['companyId'])->count())->toBe(0);
});

test('only a HOD may open a follow-up action', function (): void {
    $f = effFixture();
    $review = effReviewed($f, EffectivenessOutcome::NotYetEffective);

    expect(fn () => app(TrainingEffectivenessStore::class)
        ->openFollowUpAction($f['hr'], $f['companyId'], (int) $review->id, effFollowUpDraft($f)))
        ->toThrow(InvalidEffectivenessReviewException::class, 'Only a HOD')
        ->and(DevelopmentAction::query()->forCompany($f['tenantId'], $f['companyId'])->count())->toBe(0);
});

test('an existing open action of the same employee and course skill may be linked instead', function (): void {
    $f = effFixture();
    $review = effReviewed($f, EffectivenessOutcome::PartiallyEffective);
    $existing = app(DevelopmentActionStore::class)->proposeManual($f['companyId'], effFollowUpDraft($f, [
        'skillId' => (int) $f['skill']->id, 'startingLevel' => 2, 'targetLevel' => 4,
        'manualReason' => 'Opened from the department plan before the review closed.',
    ]), (int) $f['hod']->id);

    $linked = app(TrainingEffectivenessStore::class)
        ->openFollowUpAction($f['hod'], $f['companyId'], (int) $review->id, (int) $existing->id);

    expect((int) $linked->development_action_id)->toBe((int) $existing->id)
        ->and(DevelopmentAction::query()->forCompany($f['tenantId'], $f['companyId'])->count())->toBe(1);
});

test('an existing action of another employee is refused', function (): void {
    $f = effFixture();
    $stranger = Employee::factory()->create([
        'company_id' => $f['companyId'], 'department_id' => $f['learner']->department_id,
        'full_name' => 'Another learner', 'status' => 'active', 'employee_type' => 'full_time',
    ]);
    EmployeeWorkProfile::query()->create([
        'employee_id' => $stranger->id, 'organization_unit_id' => $f['entry']->id,
    ]);
    $review = effReviewed($f, EffectivenessOutcome::NotYetEffective);
    $theirs = app(DevelopmentActionStore::class)->proposeManual($f['companyId'], effFollowUpDraft($f, [
        'employeeEntityId' => (int) $stranger->id, 'skillId' => (int) $f['skill']->id,
        'startingLevel' => 2, 'targetLevel' => 4, 'manualReason' => 'Their own gap.',
    ]), (int) $f['hod']->id);

    expect(fn () => app(TrainingEffectivenessStore::class)
        ->openFollowUpAction($f['hod'], $f['companyId'], (int) $review->id, (int) $theirs->id))
        ->toThrow(InvalidEffectivenessReviewException::class, 'another employee')
        ->and(TrainingEffectivenessReview::query()->forCompany($f['tenantId'], $f['companyId'])
            ->sole()->development_action_id)->toBeNull();
});

test('an existing action for a skill the reviewed course does not cover is refused', function (): void {
    $f = effFixture();
    $other = app(SkillCatalogStore::class)->defineSkill($f['companyId'], new SkillDraft(
        code: 'forklift.link', name: 'Forklift basics',
        definition: 'Operate a counterbalance forklift.',
        categoryId: (int) app(SkillCatalogStore::class)->defineCategory($f['companyId'], 'plant-link', 'Plant')->id,
        defaultAssessmentMethod: AssessmentMethod::DirectObservation,
    ));
    $review = effReviewed($f, EffectivenessOutcome::NotYetEffective);
    $elsewhere = app(DevelopmentActionStore::class)->proposeManual($f['companyId'], effFollowUpDraft($f, [
        'skillId' => (int) $other->id, 'startingLevel' => 1, 'targetLevel' => 3,
        'manualReason' => 'A gap in an unrelated skill.',
    ]), (int) $f['hod']->id);

    expect(fn () => app(TrainingEffectivenessStore::class)
        ->openFollowUpAction($f['hod'], $f['companyId'], (int) $review->id, (int) $elsewhere->id))
        ->toThrow(InvalidEffectivenessReviewException::class, 'does not cover')
        ->and(TrainingEffectivenessReview::query()->forCompany($f['tenantId'], $f['companyId'])
            ->sole()->development_action_id)->toBeNull();
});

test('a cancelled action cannot be linked as the follow-up', function (): void {
    $f = effFixture();
    $review = effReviewed($f, EffectivenessOutcome::NotYetEffective);
    $actions = app(DevelopmentActionStore::class);
    $stale = $actions->proposeManual($f['companyId'], effFollowUpDraft($f, [
        'skillId' => (int) $f['skill']->id, 'startingLevel' => 2, 'targetLevel' => 4,
        'manualReason' => 'Superseded by a shutdown.',
    ]), (int) $f['hod']->id);
    $actions->cancel($f['companyId'], (int) $stale->id, 'The plant shut down before it started.', (int) $f['hod']->id);

    expect(fn () => app(TrainingEffectivenessStore::class)
        ->openFollowUpAction($f['hod'], $f['companyId'], (int) $review->id, (int) $stale->id))
        ->toThrow(InvalidEffectivenessReviewException::class, 'Link an open development action')
        ->and(TrainingEffectivenessReview::query()->forCompany($f['tenantId'], $f['companyId'])
            ->sole()->development_action_id)->toBeNull();
});

/**
 * Each level is asked for separately. A single review missing both would pass
 * whichever half of the guard was left standing, so the two are split: unknown
 * is not zero, and an action that starts or aims at a level nobody verified is
 * the silent zero this contract keeps refusing.
 */
test('the review refuses to start an action with no level to aim at', function (): void {
    $f = effFixture();
    $review = effOpen($f, ['baselineLevel' => 2, 'targetLevel' => null]);
    app(TrainingEffectivenessStore::class)->recordOutcome($f['hod'], $f['companyId'], (int) $review->id,
        effOutcomeDraft(['outcome' => EffectivenessOutcome::NotYetEffective]));

    expect(fn () => app(TrainingEffectivenessStore::class)
        ->openFollowUpAction($f['hod'], $f['companyId'], (int) $review->id, effFollowUpDraft($f)))
        ->toThrow(InvalidEffectivenessReviewException::class, 'no level to start from')
        ->and(DevelopmentAction::query()->forCompany($f['tenantId'], $f['companyId'])->count())->toBe(0);
});

test('the review refuses to start an action with no level to start from', function (): void {
    $f = effFixture();
    $review = effOpen($f, ['baselineLevel' => null, 'targetLevel' => 4]);
    app(TrainingEffectivenessStore::class)->recordOutcome($f['hod'], $f['companyId'], (int) $review->id,
        effOutcomeDraft(['outcome' => EffectivenessOutcome::NotYetEffective]));

    expect(fn () => app(TrainingEffectivenessStore::class)
        ->openFollowUpAction($f['hod'], $f['companyId'], (int) $review->id, effFollowUpDraft($f)))
        ->toThrow(InvalidEffectivenessReviewException::class, 'no level to start from')
        ->and(DevelopmentAction::query()->forCompany($f['tenantId'], $f['companyId'])->count())->toBe(0);
});

test('a follow-up needs the criticality of the requirement it closes', function (): void {
    $f = effFixture();
    $review = effReviewed($f, EffectivenessOutcome::NotYetEffective);

    expect(fn () => app(TrainingEffectivenessStore::class)->openFollowUpAction(
        $f['hod'], $f['companyId'], (int) $review->id, effFollowUpDraft($f, ['criticality' => null]),
    ))->toThrow(InvalidEffectivenessReviewException::class, 'criticality')
        ->and(DevelopmentAction::query()->forCompany($f['tenantId'], $f['companyId'])->count())->toBe(0);
});

/**
 * A development action row belonging to somewhere else.
 *
 * Written directly rather than through DevelopmentActionStore: the store would
 * refuse to open one for a company whose workforce this test never built, and
 * what is under test is whether the *link* refuses to reach across the company
 * and tenant axes, not whether the other company could have opened it.
 */
function effForeignAction(int $tenantId, int $companyId, int $employeeId, int $skillId): DevelopmentAction
{
    return DevelopmentAction::query()->create([
        'tenant_id' => $tenantId, 'company_entity_id' => $companyId,
        'action_key' => (string) Str::uuid(), 'employee_entity_id' => $employeeId, 'skill_id' => $skillId,
        'employee_name_snapshot' => 'Somebody else', 'starting_level' => 2, 'target_level' => 4,
        'gap_at_start' => 2, 'criticality' => RequirementCriticality::Critical, 'mandatory_gate' => false,
        'priority_score' => 10, 'priority_explanation' => 'Fixture row.',
        'action_type' => DevelopmentActionType::Coaching, 'objective' => 'Their objective.',
        'intervention' => 'Their intervention.', 'expected_evidence' => 'Their evidence.',
        'owner_employee_entity_id' => $employeeId, 'hr_coordinator_employee_entity_id' => $employeeId,
        'start_date' => '2027-04-01', 'due_date' => '2027-05-01',
        'status' => DevelopmentActionStatus::NotStarted, 'closure_status' => DevelopmentActionClosure::Open,
    ]);
}

test('a development action of the sibling company is never linked', function (): void {
    $f = effFixture();
    $sibling = Company::factory()->create(['tenant_id' => $f['tenant']->id]);
    $theirs = Employee::factory()->create([
        'company_id' => $sibling->id, 'full_name' => 'Sibling learner',
        'status' => 'active', 'employee_type' => 'full_time',
    ]);
    $action = effForeignAction($f['tenantId'], (int) $sibling->id, (int) $theirs->id, (int) $f['skill']->id);
    $review = effReviewed($f, EffectivenessOutcome::NotYetEffective);

    expect(fn () => app(TrainingEffectivenessStore::class)
        ->openFollowUpAction($f['hod'], $f['companyId'], (int) $review->id, (int) $action->id))
        ->toThrow(InvalidEffectivenessReviewException::class, 'was not found in this company')
        ->and(TrainingEffectivenessReview::query()->forCompany($f['tenantId'], $f['companyId'])
            ->sole()->development_action_id)->toBeNull();
});

test('another tenant development action is never loaded', function (): void {
    $f = effFixture();
    [$otherTenant, $otherCompany] = createTenantWithCompany();
    app(TenantContext::class)->set((int) $otherTenant->id);
    $catalog = app(SkillCatalogStore::class);
    $otherSkill = $catalog->defineSkill((int) $otherCompany->id, new SkillDraft(
        code: 'isolation.energy', name: 'Energy isolation',
        definition: 'Isolate stored energy before maintenance.',
        categoryId: (int) $catalog->defineCategory((int) $otherCompany->id, 'safety', 'Safety')->id,
        defaultAssessmentMethod: AssessmentMethod::DirectObservation,
    ));
    $otherEmployee = Employee::factory()->create([
        'company_id' => $otherCompany->id, 'full_name' => 'Other tenant learner',
        'status' => 'active', 'employee_type' => 'full_time',
    ]);
    $action = effForeignAction((int) $otherTenant->id, (int) $otherCompany->id,
        (int) $otherEmployee->id, (int) $otherSkill->id);
    app(TenantContext::class)->set($f['tenantId']);
    $review = effReviewed($f, EffectivenessOutcome::NotYetEffective);

    expect(fn () => app(TrainingEffectivenessStore::class)
        ->openFollowUpAction($f['hod'], $f['companyId'], (int) $review->id, (int) $action->id))
        ->toThrow(InvalidEffectivenessReviewException::class, 'was not found in this company')
        ->and(TrainingEffectivenessReview::query()->forCompany($f['tenantId'], $f['companyId'])
            ->sole()->development_action_id)->toBeNull();
});

test('a course covering more than one skill makes the caller name the one the follow-up addresses', function (): void {
    $f = effFixture();
    $catalog = app(SkillCatalogStore::class);
    $second = $catalog->defineSkill($f['companyId'], new SkillDraft(
        code: 'permit.writing', name: 'Permit writing',
        definition: 'Write a compliant work permit.',
        categoryId: (int) $catalog->defineCategory($f['companyId'], 'permits', 'Permits')->id,
        defaultAssessmentMethod: AssessmentMethod::DirectObservation,
    ));
    $course = app(TrainingCatalogStore::class)->defineCourse($f['companyId'], new TrainingCourseDraft(
        code: 'isolation.refresher', title: 'Isolation refresher',
        deliveryMode: DeliveryMode::InternalClassroom,
        skillIds: [(int) $f['skill']->id, (int) $second->id],
        internalTrainerEmployeeEntityId: (int) $f['head']->id,
    ));
    $event = app(TrainingEventStore::class)->schedule($f['companyId'], new TrainingEventDraft(
        courseId: (int) $course->id,
        startsAt: new DateTimeImmutable('2027-06-01T09:00:00+00:00'),
        endsAt: new DateTimeImmutable('2027-06-01T17:00:00+00:00'),
        capacity: 10, organizerEmployeeEntityId: (int) $f['head']->id,
        targetDepartmentEntityId: (int) $f['entry']->id,
    ));
    $participant = TrainingParticipant::query()->create([
        'tenant_id' => $f['tenantId'], 'company_entity_id' => $f['companyId'], 'event_id' => $event->id,
        'provider_id' => 'native', 'employee_subject_id' => (string) $f['learner']->id,
        'workforce_observed_at' => now(),
    ]);
    $store = app(TrainingEffectivenessStore::class);
    $review = $store->recordOutcome($f['hod'], $f['companyId'],
        (int) effOpen($f, ['participantId' => (int) $participant->id])->id,
        effOutcomeDraft(['outcome' => EffectivenessOutcome::NotYetEffective]));

    expect(fn () => $store->openFollowUpAction($f['hod'], $f['companyId'], (int) $review->id, effFollowUpDraft($f)))
        ->toThrow(InvalidEffectivenessReviewException::class, 'name the one this follow-up addresses');

    $store->openFollowUpAction($f['hod'], $f['companyId'], (int) $review->id,
        effFollowUpDraft($f, ['skillId' => (int) $second->id]));

    expect((int) DevelopmentAction::query()->forCompany($f['tenantId'], $f['companyId'])->sole()->skill_id)
        ->toBe((int) $second->id);
});

test('a HOD cannot open a follow-up action on their own training', function (): void {
    $f = effFixture();
    $own = effReviewRow($f, effParticipantFor($f, (int) $f['head']->id), (int) $f['learner']->id, [
        'state' => EffectivenessReviewState::OutcomeRecorded,
        'outcome' => EffectivenessOutcome::NotYetEffective,
        'baseline_level' => 2, 'target_level' => 4,
    ]);

    expect(fn () => app(TrainingEffectivenessStore::class)
        ->openFollowUpAction($f['hod'], $f['companyId'], (int) $own->id, effFollowUpDraft($f)))
        ->toThrow(InvalidEffectivenessReviewException::class, 'your own training')
        ->and(DevelopmentAction::query()->forCompany($f['tenantId'], $f['companyId'])->count())->toBe(0);
});

test('the database refuses a review linked to a development action of another company', function (): void {
    $f = effFixture();
    $sibling = Company::factory()->create(['tenant_id' => $f['tenant']->id]);
    $theirs = Employee::factory()->create([
        'company_id' => $sibling->id, 'full_name' => 'Sibling subject',
        'status' => 'active', 'employee_type' => 'full_time',
    ]);
    $foreign = effForeignAction($f['tenantId'], (int) $sibling->id, (int) $theirs->id, (int) $f['skill']->id);
    $mine = app(DevelopmentActionStore::class)->proposeManual($f['companyId'], effFollowUpDraft($f, [
        'skillId' => (int) $f['skill']->id, 'startingLevel' => 2, 'targetLevel' => 4,
        'manualReason' => 'Opened here.',
    ]), (int) $f['hod']->id);

    // The store is not the only write path. Each violating write gets its own
    // transaction so an aborted statement cannot poison the surrounding one.
    $insert = fn (int $actionId): bool => DB::transaction(fn (): bool => DB::table('people_training_effectiveness_reviews')->insert([
        'tenant_id' => $f['tenantId'], 'company_entity_id' => $f['companyId'],
        'training_participant_id' => (int) $f['participant']->id,
        'stage' => EffectivenessReviewStage::Day30->value,
        'due_on' => '2027-03-31', 'due_date_policy' => 'raw insert',
        'reviewer_employee_entity_id' => (int) $f['head']->id,
        'state' => EffectivenessReviewState::OutcomeRecorded->value,
        'outcome' => EffectivenessOutcome::NotYetEffective->value,
        'development_action_id' => $actionId,
        'created_at' => now(), 'updated_at' => now(),
    ]));

    expect(fn () => $insert((int) $foreign->id))
        ->toThrow(QueryException::class, 'development action of its own tenant and company');

    // Control: the same raw insert naming this company's own action is
    // accepted, so the guard refuses the reach and not the write path.
    expect($insert((int) $mine->id))->toBeTrue();

    $stored = TrainingEffectivenessReview::query()->forCompany($f['tenantId'], $f['companyId'])
        ->where('due_date_policy', 'raw insert')->sole();

    expect(fn () => DB::transaction(fn () => DB::table('people_training_effectiveness_reviews')
        ->where('id', $stored->id)
        ->update(['development_action_id' => (int) $foreign->id])))
        ->toThrow(QueryException::class, 'development action of its own tenant and company');
});
