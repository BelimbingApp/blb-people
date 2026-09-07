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
use App\Domains\People\Provider\Data\ExternalReference;
use App\Domains\People\Provider\Data\WorkforceSubject;
use App\Domains\People\Provider\Enums\WorkforceResourceType;
use App\Domains\People\Settings\Models\EmployeePortalAccess;
use App\Domains\People\Skills\Data\SkillDraft;
use App\Domains\People\Skills\Enums\DevelopmentActionType;
use App\Domains\People\Skills\Enums\RequirementCriticality;
use App\Domains\People\Skills\Models\DevelopmentAction;
use App\Domains\People\Skills\Services\SkillCatalogStore;
use App\Domains\People\Skills\Tests\Support\NativeWorkforceFixture;
use App\Domains\People\Training\Data\ParticipationFactDraft;
use App\Domains\People\Training\Data\TrainingCourseDraft;
use App\Domains\People\Training\Data\TrainingEventDraft;
use App\Domains\People\Training\Enums\AttendanceStatus;
use App\Domains\People\Training\Enums\DeliveryMode;
use App\Domains\People\Training\Enums\EffectivenessCheckpoint;
use App\Domains\People\Training\Enums\EffectivenessOutcome;
use App\Domains\People\Training\Enums\EffectivenessReviewStage;
use App\Domains\People\Training\Enums\EffectivenessReviewState;
use App\Domains\People\Training\Exceptions\InvalidTrainingEffectivenessException;
use App\Domains\People\Training\Livewire\Effectiveness\Index as EffectivenessIndex;
use App\Domains\People\Training\Models\TrainingEffectivenessAnswer;
use App\Domains\People\Training\Models\TrainingEffectivenessReminder;
use App\Domains\People\Training\Models\TrainingEffectivenessReview;
use App\Domains\People\Training\Models\TrainingParticipationFact;
use App\Domains\People\Training\Services\TrainingCatalogStore;
use App\Domains\People\Training\Services\TrainingEffectivenessCheckpoints;
use App\Domains\People\Training\Services\TrainingEventStore;
use App\Domains\People\Training\Services\TrainingParticipationStore;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;

/**
 * 0013-a: thirty, sixty and ninety days after somebody attended a course, ask
 * their HOD whether it is being used.
 *
 * The checkpoint opens on elapsed time since the event ended, not on when
 * anybody got round to recording attendance — otherwise a late record would
 * push the question out and the answer would arrive when nobody remembers the
 * training.
 *
 * Self-contained: helpers are prefixed checkpoint and live here.
 */
afterEach(function (): void {
    Carbon::setTestNow();
    app(TenantContext::class)->clear();
});

function checkpointRole(User $user, string $code): void
{
    PrincipalRole::query()->create([
        'company_id' => $user->company_id, 'principal_type' => PrincipalType::USER->value,
        'principal_id' => $user->id,
        'role_id' => Role::query()->whereNull('company_id')->where('code', $code)->sole()->id,
    ]);
}

/**
 * One company, one department with a HOD, one attended event that ended today.
 *
 * @return array<string, mixed>
 */
function checkpointFixture(string $label = 'Checkpoint'): array
{
    [$tenant, $company] = createTenantWithCompany(
        ['name' => $label.' Tenant'],
        ['name' => $label.' Company', 'status' => 'active'],
    );
    $tenantId = (int) $tenant->id;
    $companyId = (int) $company->id;
    app(TenantContext::class)->set($tenantId);
    setupAuthzRoles();

    $type = DepartmentType::query()->firstOrCreate(
        ['code' => 'ops-checkpoint'],
        ['name' => 'Operations checkpoint', 'category' => 'operational', 'is_active' => true],
    );
    $department = Department::query()->create([
        'company_id' => $companyId, 'department_type_id' => $type->id, 'status' => 'active',
    ]);
    $head = Employee::factory()->create([
        'company_id' => $companyId, 'department_id' => $department->id,
        'full_name' => 'Head '.$label, 'status' => 'active', 'employee_type' => 'full_time',
    ]);
    $department->update(['head_id' => $head->id]);

    $hod = User::factory()->create(['company_id' => $companyId, 'employee_id' => $head->id]);
    checkpointRole($hod, 'people_hod');
    EmployeePortalAccess::query()->create([
        'employee_id' => $head->id, 'user_id' => $hod->id,
        'display_name' => 'Head '.$label, 'status' => EmployeePortalAccess::STATUS_ACTIVE,
    ]);
    $hr = User::factory()->create(['company_id' => $companyId]);
    checkpointRole($hr, 'people_hr');

    $trainer = NativeWorkforceFixture::create($tenantId, WorkforceResourceType::Employee, $companyId);
    $attendee = Employee::factory()->create([
        'company_id' => $companyId, 'department_id' => $department->id,
        'full_name' => 'Attendee '.$label, 'status' => 'active', 'employee_type' => 'full_time',
    ]);

    $catalog = app(SkillCatalogStore::class);
    $category = $catalog->defineCategory($companyId, Str::lower(Str::random(12)), 'Checkpoint');
    $skill = $catalog->defineSkill($companyId, new SkillDraft(
        code: Str::lower(Str::random(12)), name: 'Checkpoint skill',
        definition: 'Applied after training', categoryId: (int) $category->id,
    ));
    $course = app(TrainingCatalogStore::class)->defineCourse($companyId, new TrainingCourseDraft(
        code: Str::lower(Str::random(12)), title: 'Forklift safety',
        deliveryMode: DeliveryMode::InternalClassroom, skillIds: [(int) $skill->id],
        internalTrainerEmployeeEntityId: (int) $trainer->id,
    ));
    // Scheduled ahead because the store refuses an event that ends in the
    // past; the helpers travel forward from ends_at to attend and to ask.
    $event = app(TrainingEventStore::class)->schedule($companyId, new TrainingEventDraft(
        courseId: (int) $course->id, startsAt: now()->addDay(), endsAt: now()->addDay()->addHours(4),
        capacity: 10, organizerEmployeeEntityId: (int) $trainer->id,
    ));

    return compact('tenantId', 'companyId', 'company', 'hod', 'hr', 'department', 'attendee', 'event');
}

/** Record attendance for the fixture's attendee, present unless told otherwise. */
function checkpointAttend(array $f, AttendanceStatus $attendance = AttendanceStatus::Present): int
{
    $store = app(TrainingParticipationStore::class);
    $session = $store->defineSession(
        $f['hr'], $f['companyId'], (int) $f['event']->id,
        (string) Str::uuid(), $f['event']->starts_at, $f['event']->ends_at,
    );
    Carbon::setTestNow($f['event']->ends_at->copy()->addHour());
    $subject = new WorkforceSubject(
        $f['tenantId'], $f['companyId'], WorkforceResourceType::Employee, (string) $f['attendee']->id,
        new ExternalReference(WorkforceResourceType::Employee, (string) $f['attendee']->id),
    );
    $fact = $store->recordAttendance($f['hr'], $f['companyId'], (int) $session->id, $subject, new ParticipationFactDraft(
        attendance: $attendance,
        actualMinutes: $attendance === AttendanceStatus::Present ? 240 : 0,
        source: 'manual',
        sourceReference: (string) Str::uuid(),
    ));

    Carbon::setTestNow();

    return (int) $fact->participant_id;
}

/** @return list<EffectivenessCheckpoint> */
function checkpointOpen(array $f, int $days): array
{
    Carbon::setTestNow($f['event']->ends_at->copy()->addDays($days));
    $open = app(TrainingEffectivenessCheckpoints::class)->open($f['tenantId'], $f['companyId']);
    Carbon::setTestNow();

    return array_map(static fn (object $row): EffectivenessCheckpoint => $row->checkpoint, $open);
}

function checkpointRun(array $f, int $days, array $options = []): int
{
    Carbon::setTestNow($f['event']->ends_at->copy()->addDays($days));
    $code = Artisan::call('people:training:effectiveness-due', array_replace([
        '--tenant' => $f['tenantId'], '--company' => $f['companyId'],
    ], $options));
    Carbon::setTestNow();

    return $code;
}

test('an event that ended thirty-one days ago opens the thirty-day checkpoint and no other', function (): void {
    $f = checkpointFixture();
    checkpointAttend($f);

    // Delete the day comparison and all three open at once, which is the
    // difference between asking three times and asking on schedule.
    expect(checkpointOpen($f, 31))->toBe([EffectivenessCheckpoint::Day30]);
});

test('nothing opens before the first checkpoint is due', function (): void {
    $f = checkpointFixture();
    checkpointAttend($f);

    expect(checkpointOpen($f, 29))->toBe([]);
});

test('each checkpoint opens in its own window', function (): void {
    $f = checkpointFixture();
    checkpointAttend($f);

    // Only the newest open one is asked: an unanswered 30-day question does
    // not keep the form open once the 60-day question has arrived.
    expect(checkpointOpen($f, 61))->toBe([EffectivenessCheckpoint::Day60])
        ->and(checkpointOpen($f, 91))->toBe([EffectivenessCheckpoint::Day90]);
});

test('somebody who did not attend is never asked about it', function (): void {
    $f = checkpointFixture();
    checkpointAttend($f, AttendanceStatus::Absent);

    // The question is whether the training is being applied. It cannot be.
    expect(checkpointOpen($f, 31))->toBe([]);
});

test('answering twice at the same checkpoint updates the one row', function (): void {
    $f = checkpointFixture();
    $participantId = checkpointAttend($f);
    $checkpoints = app(TrainingEffectivenessCheckpoints::class);

    Carbon::setTestNow($f['event']->ends_at->copy()->addDays(31));
    $checkpoints->answer($f['hod'], $f['companyId'], $participantId, EffectivenessCheckpoint::Day30, 3, 'Partly.');
    $checkpoints->answer($f['hod'], $f['companyId'], $participantId, EffectivenessCheckpoint::Day30, 5, 'Fully, on reflection.');
    Carbon::setTestNow();

    $answers = TrainingEffectivenessAnswer::query()->forCompany($f['tenantId'], $f['companyId'])->get();

    // Editable until the next checkpoint opens, so a revision is a revision
    // and not a second opinion.
    expect($answers)->toHaveCount(1)
        ->and((int) $answers->first()->rating)->toBe(5)
        ->and($answers->first()->comment)->toBe('Fully, on reflection.');
});

test('a checkpoint cannot be answered once the next one has opened', function (): void {
    $f = checkpointFixture();
    $participantId = checkpointAttend($f);

    Carbon::setTestNow($f['event']->ends_at->copy()->addDays(61));

    expect(fn () => app(TrainingEffectivenessCheckpoints::class)->answer(
        $f['hod'], $f['companyId'], $participantId, EffectivenessCheckpoint::Day30, 4, 'Late.',
    ))->toThrow(InvalidTrainingEffectivenessException::class);

    Carbon::setTestNow();
});

test('the head of another department cannot answer for this participant', function (): void {
    $f = checkpointFixture();
    $participantId = checkpointAttend($f);
    $otherType = DepartmentType::query()->firstOrCreate(
        ['code' => 'ops-other'], ['name' => 'Other', 'category' => 'operational', 'is_active' => true],
    );
    $otherDepartment = Department::query()->create([
        'company_id' => $f['companyId'], 'department_type_id' => $otherType->id, 'status' => 'active',
    ]);
    $otherHead = Employee::factory()->create([
        'company_id' => $f['companyId'], 'department_id' => $otherDepartment->id,
        'full_name' => 'Other head', 'status' => 'active', 'employee_type' => 'full_time',
    ]);
    $otherDepartment->update(['head_id' => $otherHead->id]);
    $intruder = User::factory()->create(['company_id' => $f['companyId'], 'employee_id' => $otherHead->id]);
    checkpointRole($intruder, 'people_hod');

    Carbon::setTestNow($f['event']->ends_at->copy()->addDays(31));

    // Holding the HOD role is not the same as heading this person's
    // department; the participant id comes from the request and is not trusted.
    expect(fn () => app(TrainingEffectivenessCheckpoints::class)->answer(
        $intruder, $f['companyId'], $participantId, EffectivenessCheckpoint::Day30, 5, 'Not mine to say.',
    ))->toThrow(InvalidTrainingEffectivenessException::class)
        ->and(TrainingEffectivenessAnswer::query()->forCompany($f['tenantId'], $f['companyId'])->count())->toBe(0);

    Carbon::setTestNow();
});

test('the command writes one reminder per participant and checkpoint however often it runs', function (): void {
    $f = checkpointFixture();
    checkpointAttend($f);

    expect(checkpointRun($f, 31))->toBe(0)
        ->and(checkpointRun($f, 31))->toBe(0)
        ->and(TrainingEffectivenessReminder::query()->forCompany($f['tenantId'], $f['companyId'])->count())->toBe(1);

    // The next checkpoint is a new question, so it earns its own reminder.
    checkpointRun($f, 61);

    expect(TrainingEffectivenessReminder::query()->forCompany($f['tenantId'], $f['companyId'])->count())->toBe(2);
});

test('a dry run lists without writing', function (): void {
    $f = checkpointFixture();
    checkpointAttend($f);

    expect(checkpointRun($f, 31, ['--dry-run' => true]))->toBe(0)
        ->and(TrainingEffectivenessReminder::query()->forCompany($f['tenantId'], $f['companyId'])->count())->toBe(0);
});

test('company axis: a sibling company in the same tenant is not listed here', function (): void {
    $f = checkpointFixture();
    checkpointAttend($f);
    $sibling = Company::factory()->create([
        'tenant_id' => $f['tenantId'], 'name' => 'Sibling Checkpoint Company', 'status' => 'active',
    ]);

    // Same tenant, so only the company filter can separate these.
    Carbon::setTestNow($f['event']->ends_at->copy()->addDays(31));
    $open = app(TrainingEffectivenessCheckpoints::class)->open($f['tenantId'], (int) $sibling->id);
    Carbon::setTestNow();

    expect($open)->toBe([]);
});

test('the database refuses a second reminder for the same participant and checkpoint', function (): void {
    $f = checkpointFixture();
    $participantId = checkpointAttend($f);
    checkpointRun($f, 31);

    $duplicate = fn (): TrainingEffectivenessReminder => DB::transaction(
        fn (): TrainingEffectivenessReminder => TrainingEffectivenessReminder::query()->create([
            'tenant_id' => $f['tenantId'], 'company_entity_id' => $f['companyId'],
            'event_id' => (int) $f['event']->id, 'participant_id' => $participantId,
            'checkpoint' => EffectivenessCheckpoint::Day30,
            'hod_user_id' => (int) $f['hod']->id, 'notified_at' => now(),
        ]),
    );

    // The service checks first, but the key is what makes the promise.
    // Wrapped in a transaction so the violation rolls back to a savepoint
    // rather than aborting Postgres's surrounding transaction.
    expect($duplicate)->toThrow(UniqueConstraintViolationException::class)
        ->and(TrainingEffectivenessReminder::query()->forCompany($f['tenantId'], $f['companyId'])->count())->toBe(1);
});

test('an answered checkpoint is not reminded again', function (): void {
    $f = checkpointFixture();
    $participantId = checkpointAttend($f);

    Carbon::setTestNow($f['event']->ends_at->copy()->addDays(31));
    app(TrainingEffectivenessCheckpoints::class)
        ->answer($f['hod'], $f['companyId'], $participantId, EffectivenessCheckpoint::Day30, 4, 'Applied.');
    Carbon::setTestNow();

    checkpointRun($f, 31);

    // The reminder is for silence, not for the checkpoint existing.
    expect(TrainingEffectivenessReminder::query()->forCompany($f['tenantId'], $f['companyId'])->count())->toBe(0);
});

test('an answer needs a comment and a rating inside the scale', function (int|string $rating, string $comment): void {
    $f = checkpointFixture();
    $participantId = checkpointAttend($f);
    Carbon::setTestNow($f['event']->ends_at->copy()->addDays(31));

    // A bare number is not an answer to "has the training been applied?", and
    // a rating off the scale is not a point on it.
    expect(fn () => app(TrainingEffectivenessCheckpoints::class)->answer(
        $f['hod'], $f['companyId'], $participantId, EffectivenessCheckpoint::Day30, (int) $rating, $comment,
    ))->toThrow(InvalidTrainingEffectivenessException::class)
        ->and(TrainingEffectivenessAnswer::query()->forCompany($f['tenantId'], $f['companyId'])->count())->toBe(0);

    Carbon::setTestNow();
})->with([
    'no comment' => [4, '   '],
    'below the scale' => [0, 'Applied.'],
    'above the scale' => [6, 'Applied.'],
]);

test('the page lists only the signed-in HOD\'s open questions and records an answer', function (): void {
    $f = checkpointFixture();
    $participantId = checkpointAttend($f);
    test()->withoutVite();

    Carbon::setTestNow($f['event']->ends_at->copy()->addDays(31));

    Livewire::actingAs($f['hod'])->test(EffectivenessIndex::class)
        ->assertOk()
        ->assertSee('Attendee Checkpoint')
        ->assertSee('30 days')
        ->set('rating.'.$participantId, 4)
        ->set('comment.'.$participantId, 'Using the new checks on every changeover.')
        ->call('save', $participantId);

    $answer = TrainingEffectivenessAnswer::query()->forCompany($f['tenantId'], $f['companyId'])->sole();

    expect((int) $answer->rating)->toBe(4)
        ->and((int) $answer->participant_id)->toBe($participantId);

    Carbon::setTestNow();
});

test('the page shows a HOD nothing for another department', function (): void {
    $f = checkpointFixture();
    checkpointAttend($f);
    $otherType = DepartmentType::query()->firstOrCreate(
        ['code' => 'ops-page-other'], ['name' => 'Other page', 'category' => 'operational', 'is_active' => true],
    );
    $otherDepartment = Department::query()->create([
        'company_id' => $f['companyId'], 'department_type_id' => $otherType->id, 'status' => 'active',
    ]);
    $otherHead = Employee::factory()->create([
        'company_id' => $f['companyId'], 'department_id' => $otherDepartment->id,
        'full_name' => 'Other page head', 'status' => 'active', 'employee_type' => 'full_time',
    ]);
    $otherDepartment->update(['head_id' => $otherHead->id]);
    $intruder = User::factory()->create(['company_id' => $f['companyId'], 'employee_id' => $otherHead->id]);
    checkpointRole($intruder, 'people_hod');
    test()->withoutVite();

    Carbon::setTestNow($f['event']->ends_at->copy()->addDays(31));

    Livewire::actingAs($intruder)->test(EffectivenessIndex::class)
        ->assertOk()
        ->assertDontSee('Attendee Checkpoint')
        ->assertSee('No effectiveness question is open');

    Carbon::setTestNow();
});

test('a correction that says absent closes the checkpoint the original opened', function (): void {
    $f = checkpointFixture();
    $participantId = checkpointAttend($f);

    // Control: present, so the thirty-day checkpoint is due.
    expect(checkpointOpen($f, 31))->not->toBe([]);

    $store = app(TrainingParticipationStore::class);
    $fact = TrainingParticipationFact::query()->forCompany($f['tenantId'], $f['companyId'])
        ->where('participant_id', $participantId)->sole();

    Carbon::setTestNow($f['event']->ends_at->copy()->addHours(2));
    $store->confirm($f['hr'], $f['companyId'], (int) $fact->id);
    $store->correct($f['hr'], $f['companyId'], (int) $fact->id, new ParticipationFactDraft(
        attendance: AttendanceStatus::Absent,
        actualMinutes: 0,
        source: 'manual',
        sourceReference: (string) Str::uuid(),
    ), 'Signed in for a colleague; they were not there.');
    Carbon::setTestNow();

    // The original still says Present and is still in the table. The reader
    // asks what happened, and what happened is the correction.
    expect(checkpointOpen($f, 31))->toBe([]);
});

/** A review of the fixture's attendee that the fixture's HOD owes a follow-up on. */
function checkpointReviewOwingFollowUp(array $f, int $participantId): TrainingEffectivenessReview
{
    return TrainingEffectivenessReview::query()->create([
        'tenant_id' => $f['tenantId'], 'company_entity_id' => $f['companyId'],
        'training_participant_id' => $participantId,
        'stage' => EffectivenessReviewStage::Day30,
        'due_on' => now()->toDateString(),
        'due_date_policy' => 'policy:0013 thirty days after the recorded return to work',
        'reviewer_employee_entity_id' => (int) $f['department']->head_id,
        'baseline_level' => 2, 'target_level' => 4,
        'outcome' => EffectivenessOutcome::NotYetEffective,
        'further_action' => 'Needs another supervised run before signing off.',
        'state' => EffectivenessReviewState::OutcomeRecorded,
    ]);
}

/** @return array<string, mixed> */
function checkpointFollowUpForm(array $f): array
{
    return [
        'type' => DevelopmentActionType::Coaching->value,
        'criticality' => RequirementCriticality::Critical->value,
        'owner' => (int) $f['department']->head_id,
        'coordinator' => (int) $f['department']->head_id,
        'trainer' => (int) $f['department']->head_id,
        'startDate' => now()->toDateString(),
        'dueDate' => now()->addDays(30)->toDateString(),
        'objective' => 'Reach the level the course was meant to deliver.',
        'intervention' => 'Two coached runs with the shift lead.',
        'evidence' => 'Both runs signed off with no correction.',
    ];
}

test('the page lists a review still owing a follow-up and the HOD opens the action from it', function (): void {
    $f = checkpointFixture();
    $review = checkpointReviewOwingFollowUp($f, checkpointAttend($f));

    $page = Livewire::actingAs($f['hod'])->test(EffectivenessIndex::class)
        ->assertOk()
        ->assertSee('Reviews still owing a follow-up')
        ->assertSee('Needs another supervised run');

    foreach (checkpointFollowUpForm($f) as $field => $value) {
        $page->set('followUp.'.$review->id.'.'.$field, $value);
    }

    $page->call('openFollowUp', (int) $review->id)->assertHasNoErrors();

    $action = DevelopmentAction::query()->forCompany($f['tenantId'], $f['companyId'])->sole();

    expect((int) $review->refresh()->development_action_id)->toBe((int) $action->id)
        ->and((int) $action->employee_entity_id)->toBe((int) $f['attendee']->id)
        ->and((int) $action->target_level)->toBe(4);
});

test('the page surfaces the store refusal rather than opening a second action', function (): void {
    $f = checkpointFixture();
    $review = checkpointReviewOwingFollowUp($f, checkpointAttend($f));

    $page = Livewire::actingAs($f['hod'])->test(EffectivenessIndex::class);
    foreach (checkpointFollowUpForm($f) as $field => $value) {
        $page->set('followUp.'.$review->id.'.'.$field, $value);
    }
    $page->call('openFollowUp', (int) $review->id)->assertHasNoErrors();

    foreach (checkpointFollowUpForm($f) as $field => $value) {
        $page->set('followUp.'.$review->id.'.'.$field, $value);
    }
    $page->call('openFollowUp', (int) $review->id)->assertHasErrors('followUp');

    expect(DevelopmentAction::query()->forCompany($f['tenantId'], $f['companyId'])->count())->toBe(1);
});

test('a review the signed-in HOD did not review is not listed for follow-up', function (): void {
    $f = checkpointFixture();
    $participantId = checkpointAttend($f);
    $stranger = Employee::factory()->create([
        'company_id' => $f['companyId'], 'department_id' => $f['department']->id,
        'full_name' => 'Someone else entirely', 'status' => 'active', 'employee_type' => 'full_time',
    ]);
    $review = checkpointReviewOwingFollowUp($f, $participantId);
    $review->forceFill(['reviewer_employee_entity_id' => (int) $stranger->id])->save();

    Livewire::actingAs($f['hod'])->test(EffectivenessIndex::class)
        ->assertOk()
        ->assertDontSee('Needs another supervised run');
});

test('a user without the review capability is refused the page that opens follow-ups', function (): void {
    $f = checkpointFixture();
    $outsider = User::factory()->create(['company_id' => $f['companyId']]);
    checkpointRole($outsider, 'people_employee');

    $this->actingAs($outsider)->get(route('people.training.effectiveness.index'))->assertForbidden();
});
