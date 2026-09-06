<?php

use App\Base\Authz\Enums\PrincipalType;
use App\Base\Authz\Exceptions\AuthorizationDeniedException;
use App\Base\Authz\Models\PrincipalRole;
use App\Base\Authz\Models\Role;
use App\Base\Tenancy\Contracts\TenantContext;
use App\Core\User\Models\User;
use App\Domains\People\Provider\Enums\WorkforceResourceType;
use App\Domains\People\Settings\Models\EmployeePortalAccess;
use App\Domains\People\Skills\Data\SkillDraft;
use App\Domains\People\Skills\Models\SkillActorBinding;
use App\Domains\People\Skills\Services\SkillCatalogStore;
use App\Domains\People\Skills\Tests\Support\NativeWorkforceFixture;
use App\Domains\People\Training\Data\TrainingCourseDraft;
use App\Domains\People\Training\Data\TrainingEventDraft;
use App\Domains\People\Training\Enums\DeliveryMode;
use App\Domains\People\Training\Enums\TrainingEvaluationStatus;
use App\Domains\People\Training\Models\TrainingEvaluation;
use App\Domains\People\Training\Models\TrainingParticipant;
use App\Domains\People\Training\Services\TrainingCatalogStore;
use App\Domains\People\Training\Services\TrainingEvaluationReader;
use App\Domains\People\Training\Services\TrainingEventStore;
use Illuminate\Support\Str;

/*
 * Self-contained: every helper is prefixed evaluationVisibility and lives here.
 *
 * One denial test per audience row of docs/contracts/training-evaluation.md.
 * The trainer row is a refusal, not an allowance: that contract says no
 * automatic evaluation audience is defined for the role and that teaching an
 * event is insufficient. See the question on this PR.
 */

afterEach(function (): void {
    app(TenantContext::class)->clear();
});

function evaluationVisibilityRole(User $actor, string $code): void
{
    setupAuthzRoles();
    PrincipalRole::query()->create([
        'company_id' => $actor->company_id,
        'principal_type' => PrincipalType::USER->value,
        'principal_id' => $actor->id,
        'role_id' => Role::query()->whereNull('company_id')->where('code', $code)->sole()->id,
    ]);
}

/**
 * @return array{tenantId: int, companyId: int, participantId: int, employeeUser: User, hr: User, hod: User, trainerUser: User, colleagueUser: User}
 */
function evaluationVisibilityFixture(): array
{
    [$tenant, $company] = createTenantWithCompany();
    $tenantId = (int) $tenant->id;
    $companyId = (int) $company->id;
    app(TenantContext::class)->set($tenantId);

    $trainer = NativeWorkforceFixture::create($tenantId, WorkforceResourceType::Employee, $companyId);
    $employee = NativeWorkforceFixture::create($tenantId, WorkforceResourceType::Employee, $companyId);
    $colleague = NativeWorkforceFixture::create($tenantId, WorkforceResourceType::Employee, $companyId);

    $users = [];
    foreach ([
        'hr' => ['role' => 'people_hr', 'employee' => null],
        'hod' => ['role' => 'people_hod', 'employee' => null],
        'trainerUser' => ['role' => 'people_training_trainer', 'employee' => $trainer],
        'employeeUser' => ['role' => 'people_employee', 'employee' => $employee],
        'colleagueUser' => ['role' => 'people_employee', 'employee' => $colleague],
    ] as $key => $spec) {
        $user = User::factory()->create(array_filter([
            'company_id' => $companyId,
            'employee_id' => $spec['employee']?->id,
        ]));

        if ($spec['employee'] !== null) {
            EmployeePortalAccess::query()->create([
                'employee_id' => $spec['employee']->id,
                'user_id' => $user->id,
                'display_name' => $key,
                'status' => EmployeePortalAccess::STATUS_ACTIVE,
            ]);
        }

        if ($spec['employee'] !== null) {
            // Self-scoping resolves through the Skills actor binding, not the
            // portal record alone. Worth knowing: an employee's access to their
            // own training evaluation depends on a Skills-owned binding.
            SkillActorBinding::query()->create([
                'tenant_id' => $tenantId,
                'company_entity_id' => $companyId,
                'employee_entity_id' => (int) $spec['employee']->id,
                'user_entity_id' => (int) NativeWorkforceFixture::create($tenantId, WorkforceResourceType::User, $companyId)->id,
                'platform_user_id' => (int) $user->id,
                'confirmed_by_user_id' => (int) $user->id,
                'confirmed_at' => now(),
                'review_reference' => 'binding-2026-09-06',
            ]);
        }

        evaluationVisibilityRole($user, $spec['role']);
        $users[$key] = $user;
    }

    $catalog = app(SkillCatalogStore::class);
    $category = $catalog->defineCategory($companyId, Str::lower(Str::random(12)), 'Evaluation');
    $skill = $catalog->defineSkill($companyId, new SkillDraft(
        code: Str::lower(Str::random(12)), name: 'Evaluation', definition: 'Learning', categoryId: (int) $category->id,
    ));
    $course = app(TrainingCatalogStore::class)->defineCourse($companyId, new TrainingCourseDraft(
        code: Str::lower(Str::random(12)), title: 'Evaluation', deliveryMode: DeliveryMode::InternalClassroom,
        skillIds: [(int) $skill->id], internalTrainerEmployeeEntityId: (int) $trainer->id,
    ));
    $event = app(TrainingEventStore::class)->schedule($companyId, new TrainingEventDraft(
        courseId: (int) $course->id, startsAt: now()->addDay(), endsAt: now()->addDay()->addHours(4),
        capacity: 10, organizerEmployeeEntityId: (int) $trainer->id,
    ));

    $participant = TrainingParticipant::query()->create([
        'tenant_id' => $tenantId,
        'company_entity_id' => $companyId,
        'event_id' => (int) $event->id,
        'provider_id' => 'native',
        'employee_subject_id' => (string) $employee->id,
        'workforce_observed_at' => now(),
    ]);

    TrainingEvaluation::query()->create([
        'tenant_id' => $tenantId,
        'company_entity_id' => $companyId,
        'participant_id' => (int) $participant->id,
        'event_id' => (int) $event->id,
        'employee_subject_id' => (string) $employee->id,
        'criteria_version' => '2026.1',
        'relevance' => 4,
        'overall_satisfaction' => 5,
        'issues_or_improvements' => 'The trainer rushed the practical section.',
        'status' => TrainingEvaluationStatus::Draft,
    ]);

    return [
        'tenantId' => $tenantId,
        'companyId' => $companyId,
        'participantId' => (int) $participant->id,
        ...$users,
    ];
}

test('HR sees evaluations across the company', function (): void {
    $f = evaluationVisibilityFixture();

    $visible = app(TrainingEvaluationReader::class)->visibleTo($f['hr'], $f['companyId'])->get();

    expect($visible)->toHaveCount(1);
});

test('the participant sees their own evaluation', function (): void {
    $f = evaluationVisibilityFixture();

    $visible = app(TrainingEvaluationReader::class)->visibleTo($f['employeeUser'], $f['companyId'])->get();

    expect($visible)->toHaveCount(1)
        ->and((int) $visible->first()->participant_id)->toBe($f['participantId']);
});

test('a colleague sees no one else evaluation', function (): void {
    $f = evaluationVisibilityFixture();

    // Another employee in the same company. Free text here names the trainer
    // and describes the session; a colleague has no claim on it.
    $visible = app(TrainingEvaluationReader::class)->visibleTo($f['colleagueUser'], $f['companyId'])->get();

    expect($visible)->toHaveCount(0);
});

test('the trainer who taught the event is refused outright', function (): void {
    $f = evaluationVisibilityFixture();

    // docs/contracts/training-evaluation.md: no automatic evaluation audience
    // is defined for this role, and teaching an event is insufficient. The
    // evaluation rates trainer effectiveness and carries free text about the
    // session, so this is the disclosure the contract withholds pending an
    // approved policy.
    //
    // A refusal rather than an empty list, and that is the stronger answer: the
    // trainer role simply does not hold the evaluation capability, so there is
    // no branch in the reader to get wrong later.
    expect(fn () => app(TrainingEvaluationReader::class)->visibleTo($f['trainerUser'], $f['companyId']))
        ->toThrow(AuthorizationDeniedException::class);
});

test('an evaluation from another company is never visible', function (): void {
    $f = evaluationVisibilityFixture();
    $other = evaluationVisibilityFixture();
    // Building the second fixture moved the tenant context; put it back before
    // asking the first company's HR what they can see.
    app(TenantContext::class)->set($f['tenantId']);

    $visible = app(TrainingEvaluationReader::class)->visibleTo($f['hr'], $f['companyId'])->get();

    expect($visible)->toHaveCount(1)
        ->and((int) $visible->first()->participant_id)->toBe($f['participantId'])
        ->and((int) $visible->first()->participant_id)->not->toBe($other['participantId']);
});
