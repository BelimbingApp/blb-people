<?php

use App\Base\Authz\Enums\PrincipalType;
use App\Base\Authz\Exceptions\AuthorizationDeniedException;
use App\Base\Authz\Models\PrincipalRole;
use App\Base\Authz\Models\Role;
use App\Base\Tenancy\Contracts\TenantContext;
use App\Core\Employee\Models\Employee;
use App\Core\User\Models\User;
use App\Domains\People\Settings\Models\EmployeePortalAccess;
use App\Domains\People\Skills\Data\SkillDraft;
use App\Domains\People\Skills\Services\SkillCatalogStore;
use App\Domains\People\Skills\Tests\Support\CompanyIsolationFixture;
use App\Domains\People\Training\Data\TrainingCourseDraft;
use App\Domains\People\Training\Data\TrainingEventDraft;
use App\Domains\People\Training\Enums\AttendanceStatus;
use App\Domains\People\Training\Enums\DeliveryMode;
use App\Domains\People\Training\Enums\TrainingEvaluationStatus;
use App\Domains\People\Training\Exceptions\InvalidTrainingEvaluationException;
use App\Domains\People\Training\Livewire\Evaluation\Index as EmployeeEvaluationIndex;
use App\Domains\People\Training\Livewire\Evaluations\Index as EvaluationsDashboard;
use App\Domains\People\Training\Models\TrainingEvaluation;
use App\Domains\People\Training\Models\TrainingEvent;
use App\Domains\People\Training\Models\TrainingParticipant;
use App\Domains\People\Training\Models\TrainingParticipationFact;
use App\Domains\People\Training\Models\TrainingSession;
use App\Domains\People\Training\Services\TrainingCatalogStore;
use App\Domains\People\Training\Services\TrainingEvaluationSubmissionStore;
use App\Domains\People\Training\Services\TrainingEventStore;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;

/**
 * 0012-f: HR keys in a completed paper evaluation for a named participant.
 *
 * The two guard controls the issue names are what every test here is about:
 * the row carries the actual entering actor (HR, never the participant, and
 * never nobody), and assistance is not authority to replace answers that are
 * already completed. Self-contained: helpers are prefixed `assisted`.
 */
beforeEach(function (): void {
    $this->withoutVite();
});

afterEach(function (): void {
    $this->travelBack();
    app(TenantContext::class)->clear();
});

function assistedRole(User $user, string $code): void
{
    PrincipalRole::query()->create([
        'company_id' => $user->company_id,
        'principal_type' => PrincipalType::USER->value,
        'principal_id' => $user->id,
        'role_id' => Role::query()->whereNull('company_id')->where('code', $code)->sole()->id,
    ]);
}

/** @return array{event: TrainingEvent, trainer: Employee} */
function assistedEvent(int $companyId, string $label): array
{
    $trainer = Employee::factory()->create(['company_id' => $companyId, 'full_name' => "$label Trainer", 'status' => 'active', 'employee_type' => 'full_time']);
    $catalog = app(SkillCatalogStore::class);
    $category = $catalog->defineCategory($companyId, 'safety', 'Safety');
    $skill = $catalog->defineSkill($companyId, new SkillDraft(
        code: 'isolation.energy', name: 'Energy isolation', definition: 'Isolate stored energy.', categoryId: (int) $category->id,
    ));
    $course = app(TrainingCatalogStore::class)->defineCourse($companyId, new TrainingCourseDraft(
        code: 'isolation.induction', title: "$label induction", deliveryMode: DeliveryMode::InternalClassroom,
        skillIds: [(int) $skill->id], internalTrainerEmployeeEntityId: (int) $trainer->id,
    ));
    $event = app(TrainingEventStore::class)->schedule($companyId, new TrainingEventDraft(
        courseId: (int) $course->id, startsAt: now()->addDay(), endsAt: now()->addDay()->addHours(4),
        capacity: 10, organizerEmployeeEntityId: (int) $trainer->id,
    ));

    return ['event' => $event, 'trainer' => $trainer];
}

function assistedParticipant(int $tenantId, int $companyId, TrainingEvent $event, Employee $employee, User $recorder): TrainingParticipant
{
    $participant = TrainingParticipant::query()->create([
        'tenant_id' => $tenantId, 'company_entity_id' => $companyId, 'event_id' => $event->id,
        'provider_id' => 'native', 'employee_subject_id' => (string) $employee->id, 'workforce_observed_at' => now(),
    ]);
    $session = TrainingSession::query()->firstOrCreate([
        'tenant_id' => $tenantId, 'company_entity_id' => $companyId, 'event_id' => $event->id,
        'session_reference' => 'assisted-session-'.$event->id,
    ], ['starts_at' => $event->starts_at, 'ends_at' => $event->ends_at, 'created_by_user_id' => $recorder->id]);
    TrainingParticipationFact::query()->create([
        'tenant_id' => $tenantId, 'company_entity_id' => $companyId, 'event_id' => $event->id,
        'participant_id' => $participant->id, 'session_id' => $session->id,
        'attendance' => AttendanceStatus::Present, 'actual_minutes' => 120, 'evidence_references' => [],
        'source' => 'fixture', 'source_reference' => 'assisted-fact-'.$participant->id,
        'recorded_by_user_id' => $recorder->id, 'recorded_capability' => 'fixture', 'recorded_at' => now(),
    ]);

    return $participant;
}

/**
 * Alpha is the acting company; Beta is its sibling in the same tenant. The
 * clock is moved to an hour after Alpha's event ended, so the event is over
 * and the 14-day window is open.
 *
 * @return array<string, mixed>
 */
function assistedFixture(): array
{
    $tenant = CompanyIsolationFixture::twoCompaniesInOneTenant();
    $tenantId = $tenant->tenantId;
    $companyId = $tenant->alphaCompanyEntityId;
    app(TenantContext::class)->set($tenantId);
    setupAuthzRoles();

    $hr = User::factory()->create(['company_id' => $companyId]);
    assistedRole($hr, 'people_hr');
    $hod = User::factory()->create(['company_id' => $companyId]);
    assistedRole($hod, 'people_hod');
    $employee = Employee::factory()->create(['company_id' => $companyId, 'full_name' => 'Alice Paper', 'status' => 'active', 'employee_type' => 'full_time']);
    $employeeUser = User::factory()->create(['company_id' => $companyId, 'employee_id' => $employee->id]);
    assistedRole($employeeUser, 'people_employee');
    EmployeePortalAccess::query()->create([
        'employee_id' => $employee->id, 'user_id' => $employeeUser->id,
        'display_name' => 'Alice Paper', 'status' => EmployeePortalAccess::STATUS_ACTIVE,
    ]);
    $colleague = Employee::factory()->create(['company_id' => $companyId, 'full_name' => 'Bob Colleague', 'status' => 'active', 'employee_type' => 'full_time']);

    ['event' => $event] = assistedEvent($companyId, 'Alpha');
    $participant = assistedParticipant($tenantId, $companyId, $event, $employee, $hr);
    $colleagueParticipant = assistedParticipant($tenantId, $companyId, $event, $colleague, $hr);

    $betaId = $tenant->betaCompanyEntityId;
    $betaHr = User::factory()->create(['company_id' => $betaId]);
    assistedRole($betaHr, 'people_hr');
    ['event' => $betaEvent] = assistedEvent($betaId, 'Beta');
    $betaEmployee = Employee::factory()->create(['company_id' => $betaId, 'full_name' => 'Beta Person', 'status' => 'active', 'employee_type' => 'full_time']);
    $betaParticipant = assistedParticipant($tenantId, $betaId, $betaEvent, $betaEmployee, $betaHr);

    test()->travelTo($event->ends_at->addHour());

    return compact('tenantId', 'companyId', 'hr', 'hod', 'employee', 'employeeUser', 'event', 'participant', 'colleagueParticipant', 'betaId', 'betaParticipant');
}

/** @return array<string, int> */
function assistedRatings(int $value = 4): array
{
    return [
        'relevance' => $value, 'trainer_effectiveness' => $value, 'materials_exercises' => $value,
        'pace_duration' => $value, 'practical_usefulness' => $value,
    ];
}

function assistedSubmit(array $f, User $actor, int $participantId, array $ratings, ?int $companyId = null): TrainingEvaluation
{
    return app(TrainingEvaluationSubmissionStore::class)->submitAssisted(
        $actor, $companyId ?? $f['companyId'], $participantId, $ratings, 'From the paper form.', 'FORM-12',
    );
}

function assistedRows(array $f): mixed
{
    return TrainingEvaluation::query()->forCompany($f['tenantId'], $f['companyId']);
}

test('HR enters a paper evaluation with actual-actor provenance and the employee sees the paper marker', function (): void {
    $f = assistedFixture();

    Livewire::actingAs($f['hr'])->test(EvaluationsDashboard::class)
        ->assertSee('Enter paper evaluation')
        ->assertSee('Alice Paper — Alpha induction')
        ->set('paperParticipantId', (int) $f['participant']->id)
        ->set('paperRelevance', 5)->set('paperTrainerEffectiveness', 4)->set('paperMaterialsExercises', 3)
        ->set('paperPaceDuration', 2)->set('paperPracticalUsefulness', 1)
        ->set('paperReference', 'FORM-12')->set('paperComment', 'Room was cold.')
        ->call('enterPaperEvaluation')
        ->assertHasNoErrors()
        ->assertSee('Paper evaluation entered')
        ->assertSee('1 entered from paper by HR')
        ->assertDontSee('Alice Paper — Alpha induction');

    $row = assistedRows($f)->sole();
    expect($row->entry_source)->toBe('assisted_paper')
        ->and($row->employee_subject_id)->toBe((string) $f['employee']->id)
        ->and($row->participant_id)->toBe((int) $f['participant']->id)
        ->and($row->submitted_by_user_id)->toBe((int) $f['hr']->id)
        ->and($row->submitted_by_user_id)->not->toBe((int) $f['employeeUser']->id)
        ->and($row->notes)->toBe('paper:FORM-12')
        ->and($row->issues_or_improvements)->toBe('Room was cold.')
        ->and($row->relevance)->toBe(5)
        ->and($row->practical_usefulness)->toBe(1)
        ->and($row->status)->toBe(TrainingEvaluationStatus::Completed);

    Livewire::actingAs($f['employeeUser'])->test(EmployeeEvaluationIndex::class)
        ->assertSee('Alpha induction')
        ->assertSee('Entered from paper by HR');
});

test('an employee cannot enter an assisted evaluation for another participant', function (): void {
    $f = assistedFixture();

    expect(fn () => assistedSubmit($f, $f['employeeUser'], (int) $f['colleagueParticipant']->id, assistedRatings()))
        ->toThrow(AuthorizationDeniedException::class);

    expect(assistedRows($f)->count())->toBe(0);
});

test('a HOD cannot enter an assisted evaluation', function (): void {
    $f = assistedFixture();

    expect(fn () => assistedSubmit($f, $f['hod'], (int) $f['participant']->id, assistedRatings()))
        ->toThrow(AuthorizationDeniedException::class);

    expect(assistedRows($f)->count())->toBe(0);
});

test('assisted entry never replaces a completed evaluation', function (): void {
    $f = assistedFixture();
    $own = assistedSubmit($f, $f['hr'], (int) $f['colleagueParticipant']->id, assistedRatings(5));
    app(TrainingEvaluationSubmissionStore::class)->submit(
        $f['employeeUser'], $f['companyId'], (int) $f['event']->id, assistedRatings(5), 'My own words.',
    );

    // Neither a self-submission nor an earlier paper entry is HR's to change.
    foreach ([$f['participant'], $f['colleagueParticipant']] as $participant) {
        expect(fn () => assistedSubmit($f, $f['hr'], (int) $participant->id, assistedRatings(2)))
            ->toThrow(InvalidTrainingEvaluationException::class, 'already completed');
    }

    $self = assistedRows($f)->where('participant_id', $f['participant']->id)->sole();
    expect($self->relevance)->toBe(5)
        ->and($self->entry_source)->toBe('self')
        ->and($self->submitted_by_user_id)->toBe((int) $f['employeeUser']->id)
        ->and($self->issues_or_improvements)->toBe('My own words.')
        ->and(assistedRows($f)->where('participant_id', $own->participant_id)->sole()->relevance)->toBe(5)
        ->and(assistedRows($f)->count())->toBe(2);
});

test('the database refuses assisted_paper provenance without an entering actor', function (string $path): void {
    $f = assistedFixture();
    $row = [
        'tenant_id' => $f['tenantId'], 'company_entity_id' => $f['companyId'],
        'participant_id' => $f['participant']->id, 'event_id' => $f['event']->id,
        'employee_subject_id' => (string) $f['employee']->id, 'criteria_version' => '0012-a.v1',
        'status' => 'completed', 'entry_source' => 'self', 'submitted_by_user_id' => $f['hr']->id,
        'created_at' => now(), 'updated_at' => now(),
    ];

    $write = match ($path) {
        'insert' => fn () => DB::table('people_training_evaluations')->insert([...$row, 'entry_source' => 'assisted_paper', 'submitted_by_user_id' => null]),
        'update' => function () use ($row): void {
            DB::table('people_training_evaluations')->insert($row);
            DB::table('people_training_evaluations')->where('participant_id', $row['participant_id'])
                ->update(['entry_source' => 'assisted_paper', 'submitted_by_user_id' => null]);
        },
    };

    expect($write)->toThrow(QueryException::class, 'entering actor');
    expect(assistedRows($f)->where('entry_source', 'assisted_paper')->count())->toBe(0);
})->with(['insert', 'update']);

test('a participant of the sibling company is not reachable', function (): void {
    $f = assistedFixture();

    // Named under Alpha: the participant row belongs to Beta, so it is not found.
    expect(fn () => assistedSubmit($f, $f['hr'], (int) $f['betaParticipant']->id, assistedRatings()))
        ->toThrow(InvalidTrainingEvaluationException::class, 'unavailable in the current scope');
    // Named under Beta: Alpha's HR may not act for the sibling company.
    expect(fn () => assistedSubmit($f, $f['hr'], (int) $f['betaParticipant']->id, assistedRatings(), $f['betaId']))
        ->toThrow(InvalidTrainingEvaluationException::class, 'unavailable in the current scope');

    expect(TrainingEvaluation::query()->forCompany($f['tenantId'], $f['betaId'])->count())->toBe(0);
});

test('an HR user of another tenant is refused', function (): void {
    $f = assistedFixture();
    [, $foreignCompany] = createTenantWithCompany();
    $foreignHr = User::factory()->create(['company_id' => $foreignCompany->id]);
    assistedRole($foreignHr, 'people_hr');

    expect(fn () => assistedSubmit($f, $foreignHr, (int) $f['participant']->id, assistedRatings()))
        ->toThrow(InvalidTrainingEvaluationException::class, 'unavailable in the current scope');

    expect(assistedRows($f)->count())->toBe(0);
});

test('rating bounds are enforced on the assisted path exactly as on self-submission', function (mixed $invalid): void {
    $f = assistedFixture();

    expect(fn () => assistedSubmit($f, $f['hr'], (int) $f['participant']->id, [...assistedRatings(), 'relevance' => $invalid]))
        ->toThrow(InvalidTrainingEvaluationException::class, 'from 1 to 5');

    expect(assistedRows($f)->count())->toBe(0);
})->with(['zero' => 0, 'six' => 6, 'numeric string' => '5']);

test('the 14-day window applies unchanged to assisted entry', function (): void {
    $f = assistedFixture();
    $this->travelTo($f['event']->ends_at->addDays(14)->addSecond());

    expect(fn () => assistedSubmit($f, $f['hr'], (int) $f['participant']->id, assistedRatings()))
        ->toThrow(InvalidTrainingEvaluationException::class, 'window has closed');

    expect(assistedRows($f)->count())->toBe(0);
});
