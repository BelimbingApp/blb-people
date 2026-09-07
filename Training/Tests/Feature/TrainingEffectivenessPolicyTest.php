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
use App\Domains\People\Skills\Services\SkillCatalogStore;
use App\Domains\People\Skills\Tests\Support\NativeWorkforceFixture;
use App\Domains\People\Training\Data\ParticipationFactDraft;
use App\Domains\People\Training\Data\TrainingCourseDraft;
use App\Domains\People\Training\Data\TrainingEventDraft;
use App\Domains\People\Training\Enums\AttendanceStatus;
use App\Domains\People\Training\Enums\DeliveryMode;
use App\Domains\People\Training\Enums\EffectivenessCheckpoint;
use App\Domains\People\Training\Exceptions\InvalidTrainingEffectivenessException;
use App\Domains\People\Training\Models\TrainingEffectivenessCheckpointPolicy;
use App\Domains\People\Training\Services\TrainingCatalogStore;
use App\Domains\People\Training\Services\TrainingEffectivenessAggregate;
use App\Domains\People\Training\Services\TrainingEffectivenessCheckpoints;
use App\Domains\People\Training\Services\TrainingEffectivenessPolicy;
use App\Domains\People\Training\Services\TrainingEventStore;
use App\Domains\People\Training\Services\TrainingParticipationStore;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * 0013-e: the 30/60/90-day checkpoint offsets are governed per company, and
 * the policy in force when the event ended is the one that dates that event's
 * checkpoints forever.
 *
 * Self-contained: every helper here is prefixed `effpolicy` and this file runs
 * alone.
 */
afterEach(function (): void {
    Carbon::setTestNow();
    app(TenantContext::class)->clear();
});

function effpolicyRole(User $user, string $code): void
{
    PrincipalRole::query()->create([
        'company_id' => $user->company_id, 'principal_type' => PrincipalType::USER->value,
        'principal_id' => $user->id,
        'role_id' => Role::query()->whereNull('company_id')->where('code', $code)->sole()->id,
    ]);
}

/**
 * One tenant with two companies; the first has a department with a HOD, an HR
 * user, and one attended event.
 *
 * @return array<string, mixed>
 */
function effpolicyFixture(): array
{
    [$tenant, $company] = createTenantWithCompany(
        ['name' => 'Policy Tenant'],
        ['name' => 'Policy Company', 'status' => 'active'],
    );
    $tenantId = (int) $tenant->id;
    $companyId = (int) $company->id;
    $siblingId = (int) Company::factory()->create([
        'tenant_id' => $tenantId, 'name' => 'Policy Sibling', 'status' => 'active',
    ])->id;
    app(TenantContext::class)->set($tenantId);
    setupAuthzRoles();

    $type = DepartmentType::query()->firstOrCreate(
        ['code' => 'ops-effpolicy'],
        ['name' => 'Operations effpolicy', 'category' => 'operational', 'is_active' => true],
    );
    $department = Department::query()->create([
        'company_id' => $companyId, 'department_type_id' => $type->id, 'status' => 'active',
    ]);
    $head = Employee::factory()->create([
        'company_id' => $companyId, 'department_id' => $department->id,
        'full_name' => 'Head Policy', 'status' => 'active', 'employee_type' => 'full_time',
    ]);
    $department->update(['head_id' => $head->id]);

    $hod = User::factory()->create(['company_id' => $companyId, 'employee_id' => $head->id]);
    effpolicyRole($hod, 'people_hod');
    EmployeePortalAccess::query()->create([
        'employee_id' => $head->id, 'user_id' => $hod->id,
        'display_name' => 'Head Policy', 'status' => EmployeePortalAccess::STATUS_ACTIVE,
    ]);
    $hr = User::factory()->create(['company_id' => $companyId]);
    effpolicyRole($hr, 'people_hr');

    $trainer = NativeWorkforceFixture::create($tenantId, WorkforceResourceType::Employee, $companyId);
    $attendee = Employee::factory()->create([
        'company_id' => $companyId, 'department_id' => $department->id,
        'full_name' => 'Attendee Policy', 'status' => 'active', 'employee_type' => 'full_time',
    ]);

    $catalog = app(SkillCatalogStore::class);
    $category = $catalog->defineCategory($companyId, Str::lower(Str::random(12)), 'Policy');
    $skill = $catalog->defineSkill($companyId, new SkillDraft(
        code: Str::lower(Str::random(12)), name: 'Policy skill',
        definition: 'Applied after training', categoryId: (int) $category->id,
    ));
    $course = app(TrainingCatalogStore::class)->defineCourse($companyId, new TrainingCourseDraft(
        code: Str::lower(Str::random(12)), title: 'Forklift safety',
        deliveryMode: DeliveryMode::InternalClassroom, skillIds: [(int) $skill->id],
        internalTrainerEmployeeEntityId: (int) $trainer->id,
    ));
    // Scheduled ahead because the event store refuses an event that ends in
    // the past; the helpers travel forward from ends_at to attend and to ask.
    $event = app(TrainingEventStore::class)->schedule($companyId, new TrainingEventDraft(
        courseId: (int) $course->id, startsAt: now()->addDay(), endsAt: now()->addDay()->addHours(4),
        capacity: 10, organizerEmployeeEntityId: (int) $trainer->id,
    ));

    return compact('tenantId', 'companyId', 'siblingId', 'hod', 'hr', 'attendee', 'event');
}

/** Record present attendance for the fixture's attendee. */
function effpolicyAttend(array $f): int
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
        attendance: AttendanceStatus::Present, actualMinutes: 240,
        source: 'manual', sourceReference: (string) Str::uuid(),
    ));
    Carbon::setTestNow();

    return (int) $fact->participant_id;
}

/**
 * Append a policy row directly: offsetsFor() reads history, while set() is
 * prospective and so cannot write the past a stored event needs.
 */
function effpolicyRow(array $f, int $d30, int $d60, int $d90, string $effectiveFrom, ?int $companyId = null): void
{
    TrainingEffectivenessCheckpointPolicy::query()->create([
        'tenant_id' => $f['tenantId'], 'company_entity_id' => $companyId ?? $f['companyId'],
        'day_30_offset' => $d30, 'day_60_offset' => $d60, 'day_90_offset' => $d90,
        'effective_from' => $effectiveFrom, 'set_by_user_id' => $f['hr']->id,
        'reason' => 'Governed by the group HSE calendar.',
    ]);
}

/** @return list<EffectivenessCheckpoint> */
function effpolicyOpen(array $f, int $days): array
{
    Carbon::setTestNow($f['event']->ends_at->copy()->addDays($days));
    $open = app(TrainingEffectivenessCheckpoints::class)->open($f['tenantId'], $f['companyId']);
    Carbon::setTestNow();

    return array_map(static fn (object $row): EffectivenessCheckpoint => $row->checkpoint, $open);
}

function effpolicyEventDate(array $f, int $daysBeforeEnd): string
{
    return $f['event']->ends_at->copy()->subDays($daysBeforeEnd)->toDateString();
}

test('with no policy row the workbook 30/60/90 defaults still govern', function (): void {
    $f = effpolicyFixture();
    effpolicyAttend($f);

    expect(effpolicyOpen($f, 31))->toBe([EffectivenessCheckpoint::Day30])
        ->and(effpolicyOpen($f, 29))->toBe([]);

    Carbon::setTestNow($f['event']->ends_at->copy()->addDays(31));
    $rows = app(TrainingEffectivenessAggregate::class)->perCourse($f['tenantId'], $f['companyId']);
    Carbon::setTestNow();

    expect($rows)->toHaveCount(1)
        ->and($rows[0]->checkpoints[EffectivenessCheckpoint::Day30->value]->opened)->toBe(1)
        ->and($rows[0]->checkpoints[EffectivenessCheckpoint::Day60->value]->opened)->toBe(0);
});

test('a company policy of 14/45/100 dates every checkpoint from both sides', function (): void {
    $f = effpolicyFixture();
    effpolicyAttend($f);
    effpolicyRow($f, 14, 45, 100, effpolicyEventDate($f, 1));

    expect(effpolicyOpen($f, 13))->toBe([])
        ->and(effpolicyOpen($f, 14))->toBe([EffectivenessCheckpoint::Day30])
        ->and(effpolicyOpen($f, 44))->toBe([EffectivenessCheckpoint::Day30])
        ->and(effpolicyOpen($f, 45))->toBe([EffectivenessCheckpoint::Day60])
        ->and(effpolicyOpen($f, 99))->toBe([EffectivenessCheckpoint::Day60])
        ->and(effpolicyOpen($f, 100))->toBe([EffectivenessCheckpoint::Day90]);
});

test('a policy effective after the event ended never re-dates that event', function (): void {
    $f = effpolicyFixture();
    effpolicyAttend($f);
    // Effective the day after this event ended: the event keeps the defaults.
    effpolicyRow($f, 14, 45, 100, $f['event']->ends_at->copy()->addDay()->toDateString());

    expect(effpolicyOpen($f, 14))->toBe([])
        ->and(effpolicyOpen($f, 31))->toBe([EffectivenessCheckpoint::Day30]);

    // An event that ends after it does use it.
    $later = $f['event']->ends_at->copy()->addDays(10);
    expect(app(TrainingEffectivenessPolicy::class)->offsetsFor($f['tenantId'], $f['companyId'], $later))
        ->toBe(['day_30' => 14, 'day_60' => 45, 'day_90' => 100]);
});

test('the newest policy on or before the event end wins', function (): void {
    $f = effpolicyFixture();
    effpolicyRow($f, 14, 45, 100, effpolicyEventDate($f, 10));
    effpolicyRow($f, 20, 50, 110, effpolicyEventDate($f, 2));
    // Effective on the very day the event ended, which is "on or before" it.
    // The event end carries a time of day and this column does not, so a bare
    // string compare would drop this row and quietly return the -2 day one.
    effpolicyRow($f, 25, 55, 120, effpolicyEventDate($f, 0));
    effpolicyRow($f, 7, 21, 60, $f['event']->ends_at->copy()->addDays(3)->toDateString());

    expect(app(TrainingEffectivenessPolicy::class)->offsetsFor($f['tenantId'], $f['companyId'], $f['event']->ends_at))
        ->toBe(['day_30' => 25, 'day_60' => 55, 'day_90' => 120]);
});

test('a sibling company and another tenant never read this policy', function (): void {
    $f = effpolicyFixture();
    effpolicyRow($f, 14, 45, 100, effpolicyEventDate($f, 1));
    $policies = app(TrainingEffectivenessPolicy::class);

    expect($policies->offsetsFor($f['tenantId'], $f['siblingId'], $f['event']->ends_at))
        ->toBe(['day_30' => 30, 'day_60' => 60, 'day_90' => 90])
        ->and($policies->offsetsFor($f['tenantId'] + 9_000, $f['companyId'], $f['event']->ends_at))
        ->toBe(['day_30' => 30, 'day_60' => 60, 'day_90' => 90]);
});

test('HR sets a prospective policy and every malformed one is refused', function (): void {
    $f = effpolicyFixture();
    $policies = app(TrainingEffectivenessPolicy::class);
    Carbon::setTestNow($f['event']->ends_at->copy()->addDays(5));
    $tomorrow = Carbon::now()->addDay();

    $policies->set($f['hr'], $f['companyId'], 14, 45, 100, $tomorrow, 'Group HSE calendar.');
    expect(TrainingEffectivenessCheckpointPolicy::query()->forCompany($f['tenantId'], $f['companyId'])->count())->toBe(1);

    expect(fn () => $policies->set($f['hr'], $f['companyId'], 60, 30, 90, $tomorrow, 'Swapped.'))
        ->toThrow(InvalidTrainingEffectivenessException::class, 'strictly increasing');
    expect(fn () => $policies->set($f['hr'], $f['companyId'], 0, 45, 100, $tomorrow, 'Same day.'))
        ->toThrow(InvalidTrainingEffectivenessException::class, 'at least one day');
    expect(fn () => $policies->set($f['hr'], $f['companyId'], 14, 45, 100, Carbon::now()->subDay(), 'Backdated.'))
        ->toThrow(InvalidTrainingEffectivenessException::class, 'prospective');
    expect(fn () => $policies->set($f['hr'], $f['companyId'], 14, 45, 100, $tomorrow, '   '))
        ->toThrow(InvalidTrainingEffectivenessException::class, 'reason');

    expect(TrainingEffectivenessCheckpointPolicy::query()->forCompany($f['tenantId'], $f['companyId'])->count())->toBe(1);
    Carbon::setTestNow();
});

test('a HOD who reviews effectiveness may not set the policy', function (): void {
    $f = effpolicyFixture();
    Carbon::setTestNow($f['event']->ends_at->copy()->addDays(5));

    expect(fn () => app(TrainingEffectivenessPolicy::class)
        ->set($f['hod'], $f['companyId'], 14, 45, 100, Carbon::now()->addDay(), 'HOD attempt.'))
        ->toThrow(InvalidTrainingEffectivenessException::class);

    expect(TrainingEffectivenessCheckpointPolicy::query()->forCompany($f['tenantId'], $f['companyId'])->count())->toBe(0);
    Carbon::setTestNow();
});

test('the database refuses to update or delete a policy row', function (): void {
    $f = effpolicyFixture();
    effpolicyRow($f, 14, 45, 100, effpolicyEventDate($f, 1));
    $id = (int) TrainingEffectivenessCheckpointPolicy::query()
        ->forCompany($f['tenantId'], $f['companyId'])->value('id');

    foreach ([
        fn () => DB::table('people_training_effectiveness_policies')->where('id', $id)->update(['day_30_offset' => 5]),
        fn () => DB::table('people_training_effectiveness_policies')->where('id', $id)->delete(),
    ] as $attempt) {
        expect($attempt)->toThrow(QueryException::class, 'training effectiveness policy rows are append-only');
    }

    expect(TrainingEffectivenessCheckpointPolicy::query()
        ->forCompany($f['tenantId'], $f['companyId'])->value('day_30_offset'))->toBe(14);
});

test('the due command reports the checkpoint the company policy opened', function (): void {
    $f = effpolicyFixture();
    effpolicyAttend($f);
    effpolicyRow($f, 14, 45, 100, effpolicyEventDate($f, 1));

    Carbon::setTestNow($f['event']->ends_at->copy()->addDays(45));
    Artisan::call('people:training:effectiveness-due', [
        '--tenant' => $f['tenantId'], '--company' => $f['companyId'], '--dry-run' => true,
    ]);
    $output = Artisan::output();
    Carbon::setTestNow();

    expect($output)->toContain('60 days');
    expect($output)->not->toContain('90 days');
    expect($output)->not->toContain('30 days');
});
