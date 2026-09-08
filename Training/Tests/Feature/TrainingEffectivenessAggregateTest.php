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
use App\Domains\People\Settings\Models\PeopleReferenceEntry;
use App\Domains\People\Skills\Data\SkillDraft;
use App\Domains\People\Skills\Enums\DevelopmentActionClosure;
use App\Domains\People\Skills\Enums\DevelopmentActionStatus;
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
use App\Domains\People\Training\Livewire\EffectivenessAggregate\Index as AggregateIndex;
use App\Domains\People\Training\Models\TrainingEffectivenessReview;
use App\Domains\People\Training\Models\TrainingParticipant;
use App\Domains\People\Training\Services\TrainingCatalogStore;
use App\Domains\People\Training\Services\TrainingEffectivenessAggregate;
use App\Domains\People\Training\Services\TrainingEffectivenessCheckpoints;
use App\Domains\People\Training\Services\TrainingEventStore;
use App\Domains\People\Training\Services\TrainingParticipationStore;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Livewire\Livewire;

/**
 * 0013-b: what the 30/60/90-day answers add up to, per course.
 *
 * The number that matters is the answer rate, and it is only honest if the
 * denominator is every checkpoint that has *opened* — not every checkpoint
 * that was answered. A rate over answers is always 100% and tells HR nothing
 * about the HODs who never replied, which is the thing they are looking for.
 *
 * Self-contained: helpers are prefixed aggregate and live here.
 */
afterEach(function (): void {
    Carbon::setTestNow();
    app(TenantContext::class)->clear();
});

beforeEach(function (): void {
    $this->withoutVite();
});

function aggregateRole(User $user, string $code): void
{
    PrincipalRole::query()->create([
        'company_id' => $user->company_id, 'principal_type' => PrincipalType::USER->value,
        'principal_id' => $user->id,
        'role_id' => Role::query()->whereNull('company_id')->where('code', $code)->sole()->id,
    ]);
}

/** @return array<string, mixed> */
function aggregateFixture(string $label = 'Aggregate'): array
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
        ['code' => 'agg-ops'], ['name' => 'Aggregate operations', 'category' => 'operational', 'is_active' => true],
    );
    $department = Department::query()->create([
        'company_id' => $companyId, 'department_type_id' => $type->id, 'status' => 'active',
    ]);
    $head = Employee::factory()->create([
        'company_id' => $companyId, 'department_id' => $department->id,
        'full_name' => 'Aggregate Head', 'status' => 'active', 'employee_type' => 'full_time',
    ]);
    $department->update(['head_id' => $head->id]);
    $hod = User::factory()->create(['company_id' => $companyId, 'employee_id' => $head->id]);
    aggregateRole($hod, 'people_hod');
    EmployeePortalAccess::query()->create([
        'employee_id' => $head->id, 'user_id' => $hod->id,
        'display_name' => 'Aggregate Head', 'status' => EmployeePortalAccess::STATUS_ACTIVE,
    ]);
    $hr = User::factory()->create(['company_id' => $companyId]);
    aggregateRole($hr, 'people_hr');
    $employeeUser = User::factory()->create(['company_id' => $companyId]);
    aggregateRole($employeeUser, 'people_employee');
    $trainer = NativeWorkforceFixture::create($tenantId, WorkforceResourceType::Employee, $companyId);

    return compact('tenantId', 'companyId', 'company', 'hod', 'hr', 'employeeUser', 'department', 'trainer', 'head');
}

/** One event of a named course, ending the given number of days ago. */
function aggregateEvent(array $f, string $courseTitle, int $endedDaysAgo): object
{
    $catalog = app(SkillCatalogStore::class);
    $category = $catalog->defineCategory($f['companyId'], Str::lower(Str::random(12)), 'Aggregate');
    $skill = $catalog->defineSkill($f['companyId'], new SkillDraft(
        code: Str::lower(Str::random(12)), name: $courseTitle.' skill',
        definition: 'Applied after training.', categoryId: (int) $category->id,
    ));
    $course = app(TrainingCatalogStore::class)->defineCourse($f['companyId'], new TrainingCourseDraft(
        code: Str::lower(Str::random(12)), title: $courseTitle,
        deliveryMode: DeliveryMode::InternalClassroom, skillIds: [(int) $skill->id],
        internalTrainerEmployeeEntityId: (int) $f['trainer']->id,
    ));

    // Scheduled ahead because the store refuses an event ending in the past,
    // then the clock is moved so it ended when the caller asked.
    $event = app(TrainingEventStore::class)->schedule($f['companyId'], new TrainingEventDraft(
        courseId: (int) $course->id, startsAt: now()->addDay(), endsAt: now()->addDay()->addHours(4),
        capacity: 50, organizerEmployeeEntityId: (int) $f['trainer']->id,
    ));
    $event->forceFill([
        'starts_at' => now()->subDays($endedDaysAgo)->subHours(4),
        'ends_at' => now()->subDays($endedDaysAgo),
    ])->save();

    return $event;
}

/** An attendee of the event, in the fixture's department. */
function aggregateAttendee(array $f, object $event, string $name, AttendanceStatus $attendance = AttendanceStatus::Present, ?int $departmentId = null): int
{
    $employee = Employee::factory()->create([
        'company_id' => $f['companyId'], 'department_id' => $departmentId ?? $f['department']->id,
        'full_name' => $name, 'status' => 'active', 'employee_type' => 'full_time',
    ]);
    $store = app(TrainingParticipationStore::class);
    Carbon::setTestNow($event->ends_at->copy()->subHours(4));
    $session = $store->defineSession(
        $f['hr'], $f['companyId'], (int) $event->id,
        (string) Str::uuid(), $event->starts_at, $event->ends_at,
    );
    Carbon::setTestNow($event->ends_at->copy()->addHour());
    $fact = $store->recordAttendance($f['hr'], $f['companyId'], (int) $session->id, new WorkforceSubject(
        $f['tenantId'], $f['companyId'], WorkforceResourceType::Employee, (string) $employee->id,
        new ExternalReference(WorkforceResourceType::Employee, (string) $employee->id),
    ), new ParticipationFactDraft(
        attendance: $attendance, actualMinutes: $attendance === AttendanceStatus::Present ? 240 : 0,
        source: 'manual', sourceReference: (string) Str::uuid(),
    ));
    Carbon::setTestNow();

    return (int) $fact->participant_id;
}

function aggregateAnswer(array $f, int $participantId, EffectivenessCheckpoint $checkpoint, int $rating, string $comment): void
{
    app(TrainingEffectivenessCheckpoints::class)
        ->answer($f['hod'], $f['companyId'], $participantId, $checkpoint, $rating, $comment);
}

/** @return array<string, mixed>|null */
function aggregateRow(array $f, string $courseTitle, ?int $departmentEntityId = null): ?object
{
    $rows = app(TrainingEffectivenessAggregate::class)
        ->perCourse($f['tenantId'], $f['companyId'], $departmentEntityId);

    return collect($rows)->first(static fn (object $row): bool => $row->courseTitle === $courseTitle);
}

test('the answer rate counts every checkpoint that opened, not every one answered', function (): void {
    $f = aggregateFixture();
    $event = aggregateEvent($f, 'Forklift safety', 31);
    $a = aggregateAttendee($f, $event, 'Answered One');
    $b = aggregateAttendee($f, $event, 'Answered Two');
    aggregateAttendee($f, $event, 'Silent Third');
    aggregateAnswer($f, $a, EffectivenessCheckpoint::Day30, 4, 'Using it daily.');
    aggregateAnswer($f, $b, EffectivenessCheckpoint::Day30, 5, 'Fully applied.');

    $row = aggregateRow($f, 'Forklift safety');

    // Three checkpoints opened, two were answered. Over answers alone the
    // rate would read 100% and the silent HOD would be invisible.
    expect($row->checkpoints[EffectivenessCheckpoint::Day30->value]->opened)->toBe(3)
        ->and($row->checkpoints[EffectivenessCheckpoint::Day30->value]->answered)->toBe(2)
        ->and($row->checkpoints[EffectivenessCheckpoint::Day30->value]->answerRate)->toBe(67)
        ->and($row->checkpoints[EffectivenessCheckpoint::Day30->value]->meanRating)->toBe(4.5);
});

test('a checkpoint nobody has reached yet reports no rate rather than zero', function (): void {
    $f = aggregateFixture();
    $event = aggregateEvent($f, 'Recent course', 31);
    aggregateAttendee($f, $event, 'Only Attendee');

    // The 60-day mark has not arrived. Nobody failed to answer it.
    $row = aggregateRow($f, 'Recent course');
    expect($row->checkpoints[EffectivenessCheckpoint::Day60->value]->opened)->toBe(0)
        ->and($row->checkpoints[EffectivenessCheckpoint::Day60->value]->answerRate)->toBeNull()
        ->and($row->checkpoints[EffectivenessCheckpoint::Day60->value]->meanRating)->toBeNull();
});

test('an event older than twelve months is left out', function (): void {
    $f = aggregateFixture();
    $recent = aggregateEvent($f, 'Recent intake', 40);
    aggregateAttendee($f, $recent, 'Recent Attendee');
    $old = aggregateEvent($f, 'Ancient intake', 400);
    aggregateAttendee($f, $old, 'Ancient Attendee');

    expect(aggregateRow($f, 'Recent intake'))->not->toBeNull()
        ->and(aggregateRow($f, 'Ancient intake'))->toBeNull();
});

test('comments are listed with the answering HOD', function (): void {
    $f = aggregateFixture();
    $event = aggregateEvent($f, 'Commented course', 31);
    $p = aggregateAttendee($f, $event, 'Commented Attendee');
    aggregateAnswer($f, $p, EffectivenessCheckpoint::Day30, 3, 'Partly applied; needs a refresher.');

    $row = aggregateRow($f, 'Commented course');

    expect($row->comments)->toHaveCount(1)
        ->and($row->comments[0]->comment)->toBe('Partly applied; needs a refresher.')
        ->and($row->comments[0]->answeredBy)->toBe('Aggregate Head');
});

test('the department filter narrows the roll-up', function (): void {
    $f = aggregateFixture();
    $event = aggregateEvent($f, 'Filtered course', 31);
    aggregateAttendee($f, $event, 'In Department');

    $other = DepartmentType::query()->firstOrCreate(
        ['code' => 'agg-other'], ['name' => 'Other', 'category' => 'operational', 'is_active' => true],
    );
    $otherDepartment = Department::query()->create([
        'company_id' => $f['companyId'], 'department_type_id' => $other->id, 'status' => 'active',
    ]);

    expect(aggregateRow($f, 'Filtered course', (int) $f['department']->id))->not->toBeNull()
        ->and(aggregateRow($f, 'Filtered course', (int) $otherDepartment->id))->toBeNull();
});

test("company axis: a sibling company's course never appears", function (): void {
    $f = aggregateFixture();
    $sibling = Company::factory()->create([
        'tenant_id' => $f['tenantId'], 'name' => 'Sibling Aggregate Company', 'status' => 'active',
    ]);
    $siblingFixture = array_merge($f, [
        'companyId' => (int) $sibling->id,
        'company' => $sibling,
        'hr' => User::factory()->create(['company_id' => $sibling->id]),
        'trainer' => NativeWorkforceFixture::create($f['tenantId'], WorkforceResourceType::Employee, (int) $sibling->id),
    ]);
    aggregateRole($siblingFixture['hr'], 'people_hr');
    $siblingEvent = aggregateEvent($siblingFixture, 'Sibling course', 31);
    aggregateAttendee(array_merge($siblingFixture, ['department' => $f['department']]), $siblingEvent, 'Sibling Attendee');

    app(TenantContext::class)->set($f['tenantId']);
    expect(aggregateRow($f, 'Sibling course'))->toBeNull();
});

test('the page renders for HR and refuses a user without the capability', function (): void {
    $f = aggregateFixture();
    $event = aggregateEvent($f, 'Rendered course', 31);
    $p = aggregateAttendee($f, $event, 'Rendered Attendee');
    aggregateAnswer($f, $p, EffectivenessCheckpoint::Day30, 5, 'Applied in full.');

    Livewire::actingAs($f['hr'])->test(AggregateIndex::class)
        ->assertOk()
        ->assertSee('Rendered course')
        ->assertSee('Applied in full.');

    // Asserted through the route, not by instantiating the component: the
    // `authz:` middleware is what actually guards the page in production, and
    // Livewire wraps a render-time refusal in a ViewException, which would
    // make the assertion say less than it appears to.
    test()->actingAs($f['hr'])
        ->get(route('people.training.effectiveness.summary'))
        ->assertOk();
    test()->actingAs($f['employeeUser'])
        ->get(route('people.training.effectiveness.summary'))
        ->assertForbidden();
});

test('somebody who did not attend is not counted in the denominator', function (): void {
    $f = aggregateFixture();
    $event = aggregateEvent($f, 'Half-attended course', 31);
    $present = aggregateAttendee($f, $event, 'Present One');
    aggregateAttendee($f, $event, 'Absent Two', AttendanceStatus::Absent);
    aggregateAnswer($f, $present, EffectivenessCheckpoint::Day30, 4, 'Applied.');

    // The absentee was never asked, so counting them would report a HOD who
    // ignored a question nobody put to them — a 50% rate instead of 100%.
    $row = aggregateRow($f, 'Half-attended course');
    expect($row->checkpoints[EffectivenessCheckpoint::Day30->value]->opened)->toBe(1)
        ->and($row->checkpoints[EffectivenessCheckpoint::Day30->value]->answerRate)->toBe(100);
});

/**
 * A review of this participant that handed its follow-up to a development
 * action in the given closure state.
 *
 * Both rows are written directly: TrainingEffectivenessStoreTest owns the
 * rules for opening one, and what is under test here is only which of them
 * the roll-up still counts as outstanding.
 */
function aggregateFollowUp(array $f, int $participantId, DevelopmentActionClosure $closure): int
{
    $catalog = app(SkillCatalogStore::class);
    $skill = $catalog->defineSkill($f['companyId'], new SkillDraft(
        code: Str::lower(Str::random(12)), name: 'Follow-up skill',
        definition: 'Worked on after the review.',
        categoryId: (int) $catalog->defineCategory($f['companyId'], Str::lower(Str::random(12)), 'Follow-up')->id,
    ));
    $participant = TrainingParticipant::query()->forCompany($f['tenantId'], $f['companyId'])->findOrFail($participantId);
    $action = DevelopmentAction::query()->create([
        'tenant_id' => $f['tenantId'], 'company_entity_id' => $f['companyId'],
        'action_key' => (string) Str::uuid(),
        'employee_entity_id' => (int) $participant->employee_subject_id, 'skill_id' => (int) $skill->id,
        'employee_name_snapshot' => 'Follow-up subject', 'starting_level' => 2, 'target_level' => 4,
        'gap_at_start' => 2, 'criticality' => RequirementCriticality::Critical, 'mandatory_gate' => false,
        'priority_score' => 10, 'priority_explanation' => 'Fixture row.',
        'action_type' => DevelopmentActionType::Coaching, 'objective' => 'Close the gap.',
        'intervention' => 'Coaching.', 'expected_evidence' => 'Signed permits.',
        'owner_employee_entity_id' => (int) $f['head']->id,
        'hr_coordinator_employee_entity_id' => (int) $f['head']->id,
        'start_date' => now()->toDateString(), 'due_date' => now()->addMonth()->toDateString(),
        'status' => $closure === DevelopmentActionClosure::Cancelled
            ? DevelopmentActionStatus::Cancelled
            : DevelopmentActionStatus::InProgress,
        'closure_status' => $closure,
    ]);
    TrainingEffectivenessReview::query()->create([
        'tenant_id' => $f['tenantId'], 'company_entity_id' => $f['companyId'],
        'training_participant_id' => $participantId,
        'stage' => EffectivenessReviewStage::Day30, 'due_on' => now()->toDateString(),
        'due_date_policy' => 'policy:0013 thirty days after the recorded return to work',
        'reviewer_employee_entity_id' => (int) $f['head']->id,
        'outcome' => EffectivenessOutcome::NotYetEffective,
        'state' => EffectivenessReviewState::OutcomeRecorded,
        'development_action_id' => (int) $action->id,
    ]);

    return (int) $action->id;
}

test('open follow-up counts the closure states that still owe work', function (DevelopmentActionClosure $closure, bool $counted): void {
    $f = aggregateFixture();
    $event = aggregateEvent($f, 'Isolation drill', 31);
    $participantId = aggregateAttendee($f, $event, 'Follow-up Learner');
    $actionId = aggregateFollowUp($f, $participantId, $closure);

    expect(aggregateRow($f, 'Isolation drill')->openFollowUpActionIds)
        ->toBe($counted ? [$actionId] : []);
})->with([
    'open' => [DevelopmentActionClosure::Open, true],
    'pending reassessment' => [DevelopmentActionClosure::PendingReassessment, true],
    'further action required' => [DevelopmentActionClosure::FurtherActionRequired, true],
    'closed competent' => [DevelopmentActionClosure::ClosedCompetent, false],
    'cancelled' => [DevelopmentActionClosure::Cancelled, false],
]);

test('the drill-down ids are exactly the counted open follow-ups', function (): void {
    $f = aggregateFixture();
    $event = aggregateEvent($f, 'Permit writing', 31);
    $running = aggregateFollowUp($f, aggregateAttendee($f, $event, 'Still Working'), DevelopmentActionClosure::Open);
    $more = aggregateFollowUp($f, aggregateAttendee($f, $event, 'Also Working'), DevelopmentActionClosure::FurtherActionRequired);
    aggregateFollowUp($f, aggregateAttendee($f, $event, 'Finished'), DevelopmentActionClosure::ClosedCompetent);

    $ids = aggregateRow($f, 'Permit writing')->openFollowUpActionIds;
    sort($ids);
    $expected = [$running, $more];
    sort($expected);

    expect($ids)->toBe($expected)->and(count($ids))->toBe(2);
});

test('one participant who repeated a stage owes both of its follow-ups', function (): void {
    $f = aggregateFixture();
    $event = aggregateEvent($f, 'Repeated stage', 31);
    $participantId = aggregateAttendee($f, $event, 'Twice Reviewed');
    $first = aggregateFollowUp($f, $participantId, DevelopmentActionClosure::Open);
    $second = aggregateFollowUp($f, $participantId, DevelopmentActionClosure::PendingReassessment);

    $ids = aggregateRow($f, 'Repeated stage')->openFollowUpActionIds;
    sort($ids);
    $expected = [$first, $second];
    sort($expected);

    expect($ids)->toBe($expected);
});

/**
 * Both attendees sat the same course, so the row survives either filter and the
 * follow-up ids are actually observable. Filtering to a department whose
 * checkpoints all fall away would skip the row entirely, and a leak would hide
 * behind the skip rather than fail.
 */
test('the department filter narrows open follow-ups the way it narrows the rest of the row', function (): void {
    $f = aggregateFixture();
    $elsewhere = Department::query()->create([
        'company_id' => $f['companyId'], 'status' => 'active',
        // A company holds one department per type, so the second needs its own.
        'department_type_id' => DepartmentType::query()->create([
            'code' => 'agg-ops-elsewhere', 'name' => 'Elsewhere', 'category' => 'operational', 'is_active' => true,
        ])->id,
    ]);
    $event = aggregateEvent($f, 'Filtered follow-up', 31);
    $mine = aggregateFollowUp($f, aggregateAttendee($f, $event, 'Filtered Learner'), DevelopmentActionClosure::Open);
    $theirs = aggregateFollowUp(
        $f,
        aggregateAttendee($f, $event, 'Other Department', AttendanceStatus::Present, (int) $elsewhere->id),
        DevelopmentActionClosure::Open,
    );

    $all = aggregateRow($f, 'Filtered follow-up')->openFollowUpActionIds;
    sort($all);
    $both = [$mine, $theirs];
    sort($both);

    expect(aggregateRow($f, 'Filtered follow-up', (int) $f['department']->id)->openFollowUpActionIds)->toBe([$mine])
        ->and(aggregateRow($f, 'Filtered follow-up', (int) $elsewhere->id)->openFollowUpActionIds)->toBe([$theirs])
        ->and($all)->toBe($both);
});

test('the department filter offers the identity the roll-up attributes rows by', function (): void {
    // #437: the buttons were built from PeopleReferenceEntry organization units
    // while perCourse() compares Core Employee.department_id. Two identity
    // spaces, so a match would have been a numeric coincidence.
    $f = aggregateFixture('Aggregate Namespace');
    $event = aggregateEvent($f, 'Namespace course', 31);
    $participant = aggregateAttendee($f, $event, 'Namespace Attendee');
    aggregateAnswer($f, $participant, EffectivenessCheckpoint::Day30, 5, 'Applied in full.');

    // A People reference unit exists alongside the Core department, as it does
    // in a real company. The ids have to be pushed apart deliberately: both
    // tables start at 1 in a fresh test database, so the two identity spaces
    // coincide by accident and the defect stays invisible -- which is why the
    // existing filter test, which passes Core ids straight to the service,
    // never saw it.
    $unit = null;
    foreach (range(1, 3) as $n) {
        $unit = PeopleReferenceEntry::query()->create([
            'company_id' => $f['companyId'], 'type' => PeopleReferenceEntry::TYPE_ORGANIZATION_UNIT,
            'code' => 'agg-ns-unit-'.$n, 'name' => 'Namespace Learning Team '.$n,
            'status' => PeopleReferenceEntry::STATUS_ACTIVE,
        ]);
    }
    expect((int) $unit->id)->not->toBe((int) $f['department']->id);

    $options = Livewire::actingAs($f['hr'])->test(AggregateIndex::class)->viewData('departments');
    $offered = array_keys($options);

    // A button with no label is not a usable filter either.
    expect($options)->not->toBeEmpty();
    foreach ($options as $label) {
        expect(trim((string) $label))->not->toBe('');
    }

    // Every offered option has to be an identity perCourse() can attribute a
    // row by, or the button returns an empty roll-up for a department that has
    // courses.
    expect($offered)->toContain((int) $f['department']->id)
        ->and($offered)->not->toContain((int) $unit->id);

    foreach ($offered as $optionId) {
        expect(aggregateRow($f, 'Namespace course', (int) $optionId))->not->toBeNull();
    }
});
