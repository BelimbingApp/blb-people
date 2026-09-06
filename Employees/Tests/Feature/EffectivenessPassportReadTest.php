<?php

use App\Base\Authz\Enums\PrincipalType;
use App\Base\Authz\Models\PrincipalRole;
use App\Base\Authz\Models\Role;
use App\Base\Tenancy\Contracts\TenantContext;
use App\Core\Employee\Models\Employee;
use App\Core\User\Models\User;
use App\Domains\People\Employees\Services\EmployeeStandingReader;
use App\Domains\People\Provider\Data\ExternalReference;
use App\Domains\People\Provider\Data\WorkforceSubject;
use App\Domains\People\Provider\Enums\WorkforceResourceType;
use App\Domains\People\Settings\Models\EmployeePortalAccess;
use App\Domains\People\Skills\Data\SkillDraft;
use App\Domains\People\Skills\Exceptions\SelfStandingDenied;
use App\Domains\People\Skills\Models\SkillActorBinding;
use App\Domains\People\Skills\Services\SkillCatalogStore;
use App\Domains\People\Training\Data\TrainingCourseDraft;
use App\Domains\People\Training\Data\TrainingEventDraft;
use App\Domains\People\Training\Enums\DeliveryMode;
use App\Domains\People\Training\Enums\EffectivenessOutcome;
use App\Domains\People\Training\Enums\EffectivenessReviewStage;
use App\Domains\People\Training\Enums\EffectivenessReviewState;
use App\Domains\People\Training\Models\TrainingEffectivenessReview;
use App\Domains\People\Training\Models\TrainingParticipant;
use App\Domains\People\Training\Services\TrainingCatalogStore;
use App\Domains\People\Training\Services\TrainingEventStore;
use Illuminate\Support\Str;

/*
 * Self-contained: every helper is prefixed effectivenessPassport and lives here.
 *
 * People #220 extends the employee standing read with the subject's own
 * effectiveness review outcomes per stage: outcome state and date only, no
 * reviewer material. Other subjects are refused through the standing
 * reader's own self-binding authorization.
 */

afterEach(function (): void {
    app(TenantContext::class)->clear();
});

/** @return array{user: User, subject: WorkforceSubject, employee: Employee, tenantId: int, companyId: int} */
function effectivenessPassportFixture(string $role = 'people_employee'): array
{
    [$tenant, $company] = createTenantWithCompany(['name' => 'Effectiveness passport tenant']);
    $tenantId = (int) $tenant->id;
    $companyId = (int) $company->id;
    app(TenantContext::class)->set($tenantId);
    $employee = Employee::factory()->create(['company_id' => $company->id, 'status' => 'active']);
    $user = User::factory()->create(['company_id' => $company->id, 'employee_id' => $employee->id]);
    setupAuthzRoles();
    PrincipalRole::query()->create([
        'company_id' => $company->id, 'principal_type' => PrincipalType::USER->value,
        'principal_id' => $user->id, 'role_id' => Role::query()->whereNull('company_id')->where('code', $role)->sole()->id,
    ]);
    EmployeePortalAccess::query()->create(['employee_id' => $employee->id, 'user_id' => $user->id, 'display_name' => 'Own employee', 'status' => EmployeePortalAccess::STATUS_ACTIVE]);
    SkillActorBinding::query()->create([
        'tenant_id' => $tenantId, 'company_entity_id' => $companyId, 'platform_user_id' => $user->id,
        'employee_entity_id' => $employee->id, 'user_entity_id' => $user->id,
        'confirmed_by_user_id' => $user->id, 'review_reference' => 'effectiveness-passport-fixture', 'confirmed_at' => now(),
    ]);
    $subject = new WorkforceSubject($tenantId, $companyId, WorkforceResourceType::Employee, (string) $employee->id, new ExternalReference(WorkforceResourceType::Employee, (string) $employee->id));

    return compact('user', 'subject', 'employee', 'tenantId', 'companyId');
}

function effectivenessPassportParticipant(int $tenantId, int $companyId, Employee $employee): TrainingParticipant
{
    $tag = Str::lower(Str::random(10));
    $catalog = app(SkillCatalogStore::class);
    $category = $catalog->defineCategory($companyId, "passport-{$tag}", 'Passport');
    $skill = $catalog->defineSkill($companyId, new SkillDraft("passport-{$tag}.skill", 'Passport skill', 'A measured skill.', (int) $category->id));
    $course = app(TrainingCatalogStore::class)->defineCourse($companyId, new TrainingCourseDraft(
        code: "passport-{$tag}.induction", title: 'Passport induction', deliveryMode: DeliveryMode::InternalClassroom,
        skillIds: [(int) $skill->id], internalTrainerEmployeeEntityId: (int) $employee->id,
    ));
    $event = app(TrainingEventStore::class)->schedule($companyId, new TrainingEventDraft(
        courseId: (int) $course->id, startsAt: now()->addDay(), endsAt: now()->addDay()->addHours(4),
        capacity: 10, organizerEmployeeEntityId: (int) $employee->id,
    ));

    return TrainingParticipant::query()->create([
        'tenant_id' => $tenantId, 'company_entity_id' => $companyId, 'event_id' => (int) $event->id,
        'provider_id' => 'native', 'employee_subject_id' => (string) $employee->id,
        'workforce_observed_at' => now(),
    ]);
}

function effectivenessPassportReview(
    int $tenantId,
    int $companyId,
    TrainingParticipant $participant,
    Employee $reviewer,
    EffectivenessReviewStage $stage,
    EffectivenessReviewState $state,
    ?EffectivenessOutcome $outcome = null,
    string $marker = 'PRIVATE',
): TrainingEffectivenessReview {
    return TrainingEffectivenessReview::query()->create([
        'tenant_id' => $tenantId, 'company_entity_id' => $companyId,
        'training_participant_id' => (int) $participant->id, 'stage' => $stage,
        'due_on' => now()->toDateString(), 'due_date_policy' => 'company default 30/60/90',
        'reviewed_on' => $state === EffectivenessReviewState::Open ? null : now()->toDateString(),
        'reviewer_employee_entity_id' => (int) $reviewer->id,
        'baseline_level' => 2, 'target_level' => 4,
        'application_rating' => 4, 'improvement_rating' => 3, 'impact_rating' => 5,
        'evidence' => "{$marker} REVIEWER EVIDENCE",
        'outcome' => $outcome, 'further_action' => "{$marker} FOLLOW-UP",
        'outcome_recorded_at' => $state === EffectivenessReviewState::Open ? null : now(),
        'outcome_recorded_by_user_id' => $state === EffectivenessReviewState::Open ? null : (int) $reviewer->id,
        'state' => $state, 'closure_reason' => "{$marker} CLOSURE",
    ]);
}

it('returns own effectiveness outcomes per stage with state and date only', function () {
    $f = effectivenessPassportFixture();
    $participant = effectivenessPassportParticipant($f['tenantId'], $f['companyId'], $f['employee']);
    $reviewer = Employee::factory()->create(['company_id' => $f['companyId'], 'status' => 'active']);
    effectivenessPassportReview($f['tenantId'], $f['companyId'], $participant, $reviewer,
        EffectivenessReviewStage::Day30, EffectivenessReviewState::OutcomeRecorded, EffectivenessOutcome::Effective);
    effectivenessPassportReview($f['tenantId'], $f['companyId'], $participant, $reviewer,
        EffectivenessReviewStage::Day60, EffectivenessReviewState::Open);

    $result = app(EmployeeStandingReader::class)->read($f['user'], $f['subject']);

    expect($result->effectivenessOutcomes)->toHaveCount(2)
        ->and(array_column(array_map(fn ($o) => (array) $o, $result->effectivenessOutcomes), 'stage'))
        ->toBe(['day_30', 'day_60']);
    $recorded = $result->effectivenessOutcomes[0];
    expect($recorded->state)->toBe('outcome_recorded')
        ->and($recorded->outcome)->toBe('effective')
        ->and($recorded->reviewedOn)->toBe(now()->toDateString())
        ->and($recorded->outcomeRecordedAt)->not->toBeNull();
    $open = $result->effectivenessOutcomes[1];
    expect($open->state)->toBe('open')->and($open->outcome)->toBeNull()->and($open->reviewedOn)->toBeNull();
    $serialized = json_encode($result, JSON_THROW_ON_ERROR);
    expect($serialized)->not->toContain('PRIVATE', 'reviewer_employee_entity_id', 'evidence', 'further_action', 'closure_reason', 'application_rating')
        ->and(array_keys(json_decode(json_encode($recorded, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR)))
        ->toBe(['stage', 'state', 'outcome', 'reviewedOn', 'outcomeRecordedAt']);
});

it('excludes another employee reviews from an own read', function () {
    $f = effectivenessPassportFixture();
    $participant = effectivenessPassportParticipant($f['tenantId'], $f['companyId'], $f['employee']);
    $reviewer = Employee::factory()->create(['company_id' => $f['companyId'], 'status' => 'active']);
    effectivenessPassportReview($f['tenantId'], $f['companyId'], $participant, $reviewer,
        EffectivenessReviewStage::Day30, EffectivenessReviewState::OutcomeRecorded, EffectivenessOutcome::Effective);
    $other = Employee::factory()->create(['company_id' => $f['companyId'], 'status' => 'active']);
    $otherParticipant = effectivenessPassportParticipant($f['tenantId'], $f['companyId'], $other);
    effectivenessPassportReview($f['tenantId'], $f['companyId'], $otherParticipant, $reviewer,
        EffectivenessReviewStage::Day30, EffectivenessReviewState::OutcomeRecorded, EffectivenessOutcome::NotYetEffective, 'COLLEAGUE-PRIVATE');

    $result = app(EmployeeStandingReader::class)->read($f['user'], $f['subject']);

    expect($result->effectivenessOutcomes)->toHaveCount(1)
        ->and($result->effectivenessOutcomes[0]->outcome)->toBe('effective');
    expect(json_encode($result, JSON_THROW_ON_ERROR))->not->toContain('COLLEAGUE-PRIVATE');
});

it('refuses another subject even with HR authority', function () {
    $f = effectivenessPassportFixture('people_hr');
    $other = Employee::factory()->create(['company_id' => $f['companyId'], 'status' => 'active']);
    $subject = new WorkforceSubject($f['subject']->tenantId, $f['subject']->companyId, WorkforceResourceType::Employee, (string) $other->id);
    expect(fn () => app(EmployeeStandingReader::class)->read($f['user'], $subject))->toThrow(SelfStandingDenied::class);
});

it('refuses a mismatched company scope', function () {
    $f = effectivenessPassportFixture();
    $subject = new WorkforceSubject($f['subject']->tenantId, $f['subject']->companyId + 1, $f['subject']->type, $f['subject']->stableId);
    expect(fn () => app(EmployeeStandingReader::class)->read($f['user'], $subject))->toThrow(SelfStandingDenied::class);
});
