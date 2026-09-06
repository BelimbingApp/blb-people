<?php

use App\Base\Authz\Contracts\AuthorizationService;
use App\Base\Authz\Enums\PrincipalType;
use App\Base\Authz\Models\PrincipalRole;
use App\Base\Authz\Models\Role;
use App\Base\Authz\Policies\GrantPolicy;
use App\Base\Authz\Services\AuthorizationEngine;
use App\Base\Tenancy\Contracts\TenantContext;
use App\Core\Employee\Models\Employee;
use App\Core\User\Models\User;
use App\Domains\People\Employees\Livewire\MyStanding;
use App\Domains\People\Provider\Data\WorkforceSubject;
use App\Domains\People\Provider\Enums\WorkforceResourceType;
use App\Domains\People\Settings\Models\EmployeePortalAccess;
use App\Domains\People\Skills\Contracts\ReadsOwnSkillStanding;
use App\Domains\People\Skills\Data\OwnAssessmentOutcome;
use App\Domains\People\Skills\Data\OwnSkillStanding;
use App\Domains\People\Skills\Data\SkillDraft;
use App\Domains\People\Skills\Models\SkillActorBinding;
use App\Domains\People\Skills\Services\SkillAudience;
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
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;

function employeeStandingPageUser(): array
{
    [$tenant, $company] = createTenantWithCompany(['name' => 'Standing company']);
    app(TenantContext::class)->set((int) $tenant->id);
    $employee = Employee::factory()->create([
        'company_id' => $company->id,
        'full_name' => 'Amina Employee',
        'status' => 'active',
    ]);
    $user = User::factory()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
    ]);
    setupAuthzRoles();
    PrincipalRole::query()->create([
        'company_id' => $company->id,
        'principal_type' => PrincipalType::USER->value,
        'principal_id' => $user->id,
        'role_id' => Role::query()->whereNull('company_id')->where('code', 'people_employee')->sole()->id,
    ]);

    return [$user, $employee];
}

function employeeStandingPageReader(User $user, Employee $employee): void
{
    $outcome = new OwnAssessmentOutcome(
        assessmentId: 81,
        skillId: 15,
        requirementReference: 'safety.inspection',
        requirementVersion: 3,
        requiredLevel: 4,
        assessedLevel: 2,
        gap: 2,
        resultBand: 'major_gap',
        assessedAt: '2026-09-01T09:00:00+08:00',
        finalizedAt: '2026-09-02T10:00:00+08:00',
        validUntil: '2027-09-01',
        nextAssessmentDue: '2027-08-01',
    );
    $generatedAt = new DateTimeImmutable('2026-09-06T11:30:00+08:00');
    $reader = Mockery::mock(ReadsOwnSkillStanding::class);
    $reader->shouldReceive('read')
        ->withArgs(function (?User $actor, WorkforceSubject $subject, mixed $asOf) use ($user, $employee): bool {
            return $actor?->is($user)
                && $subject->tenantId === (int) $user->tenant_id
                && $subject->companyId === (int) $user->company_id
                && $subject->stableId === (string) $employee->id
                && $asOf === null;
        })
        ->andReturn(new OwnSkillStanding(
            new WorkforceSubject((int) $user->tenant_id, (int) $user->company_id, WorkforceResourceType::Employee, (string) $employee->id),
            $generatedAt,
            $generatedAt->modify('-5 minutes'),
            [$outcome],
            [$outcome],
        ));
    app()->instance(ReadsOwnSkillStanding::class, $reader);
}

it('renders the signed-in employees published standing without another-subject route', function () {
    [$user, $employee] = employeeStandingPageUser();
    employeeStandingPageReader($user, $employee);

    expect(route('people.employee-standing.show', absolute: false))->toBe('/people/my-standing');

    Livewire::actingAs($user)
        ->test(MyStanding::class)
        ->assertSee('My skill standing')
        ->assertSee('safety.inspection')
        ->assertSee('Below requirement')
        ->assertSee('Training history is not available');
});

it('refuses a crafted Livewire update that targets another employee', function () {
    [$user, $employee] = employeeStandingPageUser();
    $other = Employee::factory()->create(['company_id' => $user->company_id, 'status' => 'active']);
    employeeStandingPageReader($user, $employee);

    expect(fn () => Livewire::actingAs($user)
        ->test(MyStanding::class)
        ->set('subjectId', (string) $other->id))
        ->toThrow(CannotUpdateLockedPropertyException::class);
});

it('keeps the capability middleware on the standing route', function (): void {
    $route = collect(Route::getRoutes()->getRoutesByName()['people.employee-standing.show']->gatherMiddleware());

    expect($route)->toContain('authz:people.skill.assessment.view');
});

it('requires the assessment view capability on the standing route', function (): void {
    [$user, $employee] = employeeStandingPageUser();
    $unprivileged = User::factory()->create([
        'company_id' => $user->company_id,
        'employee_id' => $employee->id,
    ]);

    $this->actingAs($unprivileged)
        ->get(route('people.employee-standing.show'))
        ->assertForbidden();

    // Authorization snapshots are request-scoped. Begin the next request's graph.
    foreach ([GrantPolicy::class, AuthorizationEngine::class, AuthorizationService::class, SkillAudience::class] as $binding) {
        app()->forgetInstance($binding);
    }

    PrincipalRole::query()->create([
        'company_id' => $unprivileged->company_id,
        'principal_type' => PrincipalType::USER->value,
        'principal_id' => $unprivileged->id,
        'role_id' => Role::query()->whereNull('company_id')->where('code', 'people_employee')->sole()->id,
    ]);
    EmployeePortalAccess::query()->create([
        'employee_id' => $employee->id, 'user_id' => $unprivileged->id,
        'display_name' => 'Route employee', 'status' => EmployeePortalAccess::STATUS_ACTIVE,
    ]);
    SkillActorBinding::query()->create([
        'tenant_id' => $user->tenant_id, 'company_entity_id' => $user->company_id,
        'platform_user_id' => $unprivileged->id, 'employee_entity_id' => $employee->id,
        'user_entity_id' => $unprivileged->id, 'confirmed_by_user_id' => $unprivileged->id,
        'review_reference' => 'standing-route-fixture', 'confirmed_at' => now(),
    ]);

    $this->actingAs($unprivileged)
        ->get(route('people.employee-standing.show'))
        ->assertOk();
});

/*
 * Self-contained: every helper is prefixed standingPage and lives here, so
 * this file never borrows fixtures from another test file.
 */
function standingPageEffectivenessReview(
    int $tenantId,
    int $companyId,
    Employee $employee,
    EffectivenessReviewStage $stage,
    string $reviewerName,
): TrainingEffectivenessReview {
    $tag = Str::lower(Str::random(10));
    $catalog = app(SkillCatalogStore::class);
    $category = $catalog->defineCategory($companyId, "standing-{$tag}", 'Standing');
    $skill = $catalog->defineSkill($companyId, new SkillDraft(
        "standing-{$tag}.skill", 'Standing skill', 'A measured skill.', (int) $category->id,
    ));
    $course = app(TrainingCatalogStore::class)->defineCourse($companyId, new TrainingCourseDraft(
        code: "standing-{$tag}.induction", title: 'Standing induction', deliveryMode: DeliveryMode::InternalClassroom,
        skillIds: [(int) $skill->id], internalTrainerEmployeeEntityId: (int) $employee->id,
    ));
    $event = app(TrainingEventStore::class)->schedule($companyId, new TrainingEventDraft(
        courseId: (int) $course->id, startsAt: now()->addDay(), endsAt: now()->addDay()->addHours(4),
        capacity: 10, organizerEmployeeEntityId: (int) $employee->id,
    ));
    $participant = TrainingParticipant::query()->create([
        'tenant_id' => $tenantId, 'company_entity_id' => $companyId, 'event_id' => (int) $event->id,
        'provider_id' => 'native', 'employee_subject_id' => (string) $employee->id,
        'workforce_observed_at' => now(),
    ]);
    $reviewer = Employee::factory()->create([
        'company_id' => $companyId, 'status' => 'active', 'short_name' => $reviewerName,
    ]);

    return TrainingEffectivenessReview::query()->create([
        'tenant_id' => $tenantId, 'company_entity_id' => $companyId,
        'training_participant_id' => (int) $participant->id, 'stage' => $stage,
        'due_on' => now()->toDateString(), 'due_date_policy' => 'standing page fixture',
        'reviewed_on' => now()->toDateString(),
        'reviewer_employee_entity_id' => (int) $reviewer->id,
        'baseline_level' => 2, 'target_level' => 4,
        'application_rating' => 4, 'improvement_rating' => 3, 'impact_rating' => 5,
        'evidence' => 'STANDING PAGE REVIEWER EVIDENCE',
        'outcome' => EffectivenessOutcome::Effective, 'further_action' => 'STANDING PAGE FOLLOW-UP',
        'outcome_recorded_at' => now(), 'outcome_recorded_by_user_id' => (int) $reviewer->id,
        'state' => EffectivenessReviewState::OutcomeRecorded, 'closure_reason' => 'STANDING PAGE CLOSURE',
    ]);
}

it('renders the employees own effectiveness outcomes', function (): void {
    [$user, $employee] = employeeStandingPageUser();
    employeeStandingPageReader($user, $employee);
    standingPageEffectivenessReview(
        (int) $user->tenant_id, (int) $user->company_id, $employee,
        EffectivenessReviewStage::Day30, 'Standing Page Reviewer',
    );

    Livewire::actingAs($user)
        ->test(MyStanding::class)
        ->assertSee('Training effectiveness')
        ->assertSee('Day 30')
        ->assertSee('Effective');
});

it('never renders another employees effectiveness data', function (): void {
    [$user, $employee] = employeeStandingPageUser();
    employeeStandingPageReader($user, $employee);
    standingPageEffectivenessReview(
        (int) $user->tenant_id, (int) $user->company_id, $employee,
        EffectivenessReviewStage::Day30, 'Standing Page Reviewer',
    );
    $other = Employee::factory()->create(['company_id' => $user->company_id, 'status' => 'active']);
    standingPageEffectivenessReview(
        (int) $user->tenant_id, (int) $user->company_id, $other,
        EffectivenessReviewStage::Day90, 'Other Employee Reviewer',
    );

    Livewire::actingAs($user)
        ->test(MyStanding::class)
        ->assertSee('Day 30')
        ->assertDontSee('Day 90')
        ->assertDontSee('Standing Page Reviewer')
        ->assertDontSee('Other Employee Reviewer')
        ->assertDontSee('STANDING PAGE REVIEWER EVIDENCE')
        ->assertDontSee('STANDING PAGE FOLLOW-UP')
        ->assertDontSee('STANDING PAGE CLOSURE');
});
