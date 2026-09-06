<?php

use App\Base\Authz\Enums\PrincipalType;
use App\Base\Authz\Models\PrincipalRole;
use App\Base\Authz\Models\Role;
use App\Base\Tenancy\Contracts\TenantContext;
use App\Core\Employee\Models\Employee;
use App\Core\User\Models\User;
use App\Domains\People\Provider\Data\ExternalReference;
use App\Domains\People\Provider\Data\WorkforceSubject;
use App\Domains\People\Provider\Enums\WorkforceResourceType;
use App\Domains\People\Settings\Models\EmployeePortalAccess;
use App\Domains\People\Skills\Data\SkillDraft;
use App\Domains\People\Skills\Services\SkillCatalogStore;
use App\Domains\People\Skills\Tests\Support\NativeWorkforceFixture;
use App\Domains\People\Training\Data\ParticipationFactDraft;
use App\Domains\People\Training\Data\TrainingCourseDraft;
use App\Domains\People\Training\Data\TrainingEventDraft;
use App\Domains\People\Training\Enums\AttendanceStatus;
use App\Domains\People\Training\Enums\DeliveryMode;
use App\Domains\People\Training\Enums\TrainingEvaluationStatus;
use App\Domains\People\Training\Livewire\Evaluation\Index;
use App\Domains\People\Training\Models\TrainingEvaluation;
use App\Domains\People\Training\Services\TrainingCatalogStore;
use App\Domains\People\Training\Services\TrainingEventStore;
use App\Domains\People\Training\Services\TrainingParticipationStore;
use Illuminate\Support\Str;
use Livewire\Livewire;

afterEach(function (): void {
    $this->travelBack();
    app(TenantContext::class)->clear();
});

beforeEach(function (): void {
    $this->withoutVite();
});

function evaluationSubmissionRole(User $user, string $code): void
{
    setupAuthzRoles();
    PrincipalRole::query()->create([
        'company_id' => $user->company_id,
        'principal_type' => PrincipalType::USER->value,
        'principal_id' => $user->id,
        'role_id' => Role::query()->whereNull('company_id')->where('code', $code)->sole()->id,
    ]);
}

/** @return array<string, mixed> */
function evaluationSubmissionFixture(): array
{
    [$tenant, $company] = createTenantWithCompany();
    app(TenantContext::class)->set((int) $tenant->id);
    $trainer = NativeWorkforceFixture::create((int) $tenant->id, WorkforceResourceType::Employee, (int) $company->id);
    $employee = NativeWorkforceFixture::create((int) $tenant->id, WorkforceResourceType::Employee, (int) $company->id);
    $otherEmployee = NativeWorkforceFixture::create((int) $tenant->id, WorkforceResourceType::Employee, (int) $company->id);
    $hr = User::factory()->create(['company_id' => $company->id]);
    evaluationSubmissionRole($hr, 'people_hr');
    $user = User::factory()->create(['company_id' => $company->id, 'employee_id' => $employee->id]);
    evaluationSubmissionRole($user, 'people_employee');
    EmployeePortalAccess::query()->create([
        'employee_id' => $employee->id,
        'user_id' => $user->id,
        'display_name' => $employee->full_name,
        'status' => EmployeePortalAccess::STATUS_ACTIVE,
    ]);

    $catalog = app(SkillCatalogStore::class);
    $category = $catalog->defineCategory((int) $company->id, Str::lower(Str::random(12)), 'Evaluation');
    $skill = $catalog->defineSkill((int) $company->id, new SkillDraft(
        code: Str::lower(Str::random(12)), name: 'Evaluation', definition: 'Post-training feedback', categoryId: (int) $category->id,
    ));
    $course = app(TrainingCatalogStore::class)->defineCourse((int) $company->id, new TrainingCourseDraft(
        code: Str::lower(Str::random(12)), title: 'Forklift safety', deliveryMode: DeliveryMode::InternalClassroom,
        skillIds: [(int) $skill->id], internalTrainerEmployeeEntityId: (int) $trainer->id,
    ));
    $event = app(TrainingEventStore::class)->schedule((int) $company->id, new TrainingEventDraft(
        courseId: (int) $course->id, startsAt: now()->addDay(), endsAt: now()->addDay()->addHours(4),
        capacity: 10, organizerEmployeeEntityId: (int) $trainer->id,
    ));

    return compact('tenant', 'company', 'employee', 'otherEmployee', 'hr', 'user', 'course', 'event');
}

function evaluationSubmissionAttendance(array $fixture, ?Employee $employee = null): mixed
{
    $store = app(TrainingParticipationStore::class);
    $session = $store->defineSession(
        $fixture['hr'], (int) $fixture['company']->id, (int) $fixture['event']->id,
        (string) Str::uuid(), $fixture['event']->starts_at, $fixture['event']->ends_at,
    );
    test()->travelTo($fixture['event']->ends_at->addHour());
    $employee ??= $fixture['employee'];
    $subject = new WorkforceSubject(
        (int) $fixture['tenant']->id, (int) $fixture['company']->id, WorkforceResourceType::Employee,
        (string) $employee->id,
        new ExternalReference(WorkforceResourceType::Employee, (string) $employee->id),
    );

    return $store->recordAttendance($fixture['hr'], (int) $fixture['company']->id, (int) $session->id, $subject, new ParticipationFactDraft(
        attendance: AttendanceStatus::Present,
        actualMinutes: 120,
        source: 'manual',
        sourceReference: (string) Str::uuid(),
    ));
}

function evaluationSubmissionValues(string $comment, int $offset = 0): array
{
    return [
        'relevance' => 5 - $offset,
        'trainerEffectiveness' => 4 - $offset,
        'materialsExercises' => 3 + $offset,
        'paceDuration' => 2 + $offset,
        'practicalUsefulness' => 1 + $offset,
        'comment' => $comment,
    ];
}

function evaluationSubmissionFill(mixed $component, array $values): mixed
{
    foreach ($values as $field => $value) {
        $component->set($field, $value);
    }

    return $component;
}

function evaluationSubmissionRows(array $fixture): mixed
{
    return TrainingEvaluation::query()->forCompany(
        (int) $fixture['tenant']->id,
        (int) $fixture['company']->id,
    );
}

test('an employee creates then updates one evaluation for their own attended event', function (): void {
    $fixture = evaluationSubmissionFixture();
    $otherFact = evaluationSubmissionAttendance($fixture, $fixture['otherEmployee']);
    $fact = evaluationSubmissionAttendance($fixture);

    $component = Livewire::withQueryParams(['participant_id' => $otherFact->participant_id])
        ->actingAs($fixture['user'])
        ->test(Index::class)
        ->assertSee('Forklift safety');

    evaluationSubmissionFill($component, evaluationSubmissionValues('More practical exercises would help.'))
        ->call('submit', (int) $fixture['event']->id)
        ->assertHasNoErrors()
        ->assertSee('Evaluation saved');

    $first = evaluationSubmissionRows($fixture)->sole();
    expect($first->participant_id)->toBe((int) $fact->participant_id)
        ->and($first->participant_id)->not->toBe((int) $otherFact->participant_id)
        ->and($first->relevance)->toBe(5)
        ->and($first->trainer_effectiveness)->toBe(4)
        ->and($first->materials_exercises)->toBe(3)
        ->and($first->pace_duration)->toBe(2)
        ->and($first->practical_usefulness)->toBe(1)
        ->and($first->issues_or_improvements)->toBe('More practical exercises would help.')
        ->and($first->status)->toBe(TrainingEvaluationStatus::Completed)
        ->and($first->submitted_by_user_id)->toBe($fixture['user']->id);

    evaluationSubmissionFill($component, evaluationSubmissionValues('The revised pace was right.', 1))
        ->call('submit', (int) $fixture['event']->id)
        ->assertHasNoErrors();

    $updated = evaluationSubmissionRows($fixture)->sole();
    expect($updated->id)->toBe($first->id)
        ->and($updated->participant_id)->toBe((int) $fact->participant_id)
        ->and($updated->relevance)->toBe(4)
        ->and($updated->issues_or_improvements)->toBe('The revised pace was right.')
        ->and(evaluationSubmissionRows($fixture)->count())->toBe(1);
});

test('an evaluation after the event window closes is visibly refused', function (): void {
    $fixture = evaluationSubmissionFixture();
    evaluationSubmissionAttendance($fixture);
    $this->travelTo($fixture['event']->ends_at->addDays(14)->addSecond());

    $component = Livewire::actingAs($fixture['user'])->test(Index::class);
    evaluationSubmissionFill($component, evaluationSubmissionValues('This is late.'))
        ->call('submit', (int) $fixture['event']->id)
        ->assertHasErrors('evaluation')
        ->assertSee('window has closed');

    expect(evaluationSubmissionRows($fixture)->count())->toBe(0);
});

test('the evaluation route is employee-only', function (): void {
    $fixture = evaluationSubmissionFixture();

    $this->actingAs($fixture['user'])->get(route('people.training.evaluations.index'))->assertOk()->assertSee('Training evaluation');
    $this->actingAs($fixture['hr'])->get(route('people.training.evaluations.index'))->assertForbidden();
});
