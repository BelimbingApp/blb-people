<?php

use App\Base\Authz\Enums\PrincipalType;
use App\Base\Authz\Exceptions\AuthorizationDeniedException;
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
use App\Domains\People\Skills\Services\SkillCatalogStore;
use App\Domains\People\Skills\Tests\Support\NativeWorkforceFixture;
use App\Domains\People\Training\Data\ParticipationFactDraft;
use App\Domains\People\Training\Data\TrainingCourseDraft;
use App\Domains\People\Training\Data\TrainingEventDraft;
use App\Domains\People\Training\Enums\AttendanceStatus;
use App\Domains\People\Training\Enums\DeliveryMode;
use App\Domains\People\Training\Enums\EffectivenessCheckpoint;
use App\Domains\People\Training\Livewire\EffectivenessAggregate\Index as AggregateIndex;
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
function aggregateAttendee(array $f, object $event, string $name): int
{
    $employee = Employee::factory()->create([
        'company_id' => $f['companyId'], 'department_id' => $f['department']->id,
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
        attendance: AttendanceStatus::Present, actualMinutes: 240,
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

    expect(fn () => Livewire::actingAs($f['employeeUser'])->test(AggregateIndex::class))
        ->toThrow(AuthorizationDeniedException::class);
});
