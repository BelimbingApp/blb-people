<?php

use App\Base\Authz\Enums\PrincipalType;
use App\Base\Authz\Models\PrincipalRole;
use App\Base\Authz\Models\Role;
use App\Base\Menu\Services\MenuConditionRegistry;
use App\Base\Tenancy\Contracts\TenantContext;
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
use App\Domains\People\Training\Livewire\Effectiveness\Index as ReviewIndex;
use App\Domains\People\Training\Livewire\EffectivenessAggregate\Index as AggregateIndex;
use App\Domains\People\Training\Services\TrainingCatalogStore;
use App\Domains\People\Training\Services\TrainingEventStore;
use App\Domains\People\Training\Services\TrainingParticipationStore;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Livewire\Livewire;

/**
 * #436: one Effectiveness area with Review and Summary tabs.
 *
 * The whole risk of combining two menu entries is that the combination becomes
 * the access: a tab an actor cannot use, a hub that lets somebody in, or a menu
 * item that shows the area to whoever holds neither grant. Every test here is
 * about that boundary, plus the empty states that tell a reader whose queue
 * they are looking at.
 *
 * Self-contained: helpers are prefixed `tabs` and live in this file.
 */
afterEach(function (): void {
    Carbon::setTestNow();
    app(TenantContext::class)->clear();
});

function tabsRole(User $user, string $code): void
{
    PrincipalRole::query()->create([
        'company_id' => $user->company_id,
        'principal_type' => PrincipalType::USER->value,
        'principal_id' => $user->id,
        'role_id' => Role::query()->whereNull('company_id')->where('code', $code)->sole()->id,
    ]);
}

/** @return array<string, mixed> */
function tabsFixture(): array
{
    [$tenant, $company] = createTenantWithCompany(
        ['name' => 'Tabs Tenant'],
        ['name' => 'Tabs Company', 'status' => 'active'],
    );
    $tenantId = (int) $tenant->id;
    $companyId = (int) $company->id;
    app(TenantContext::class)->set($tenantId);
    setupAuthzRoles();

    $type = DepartmentType::query()->firstOrCreate(
        ['code' => 'tabs-ops'],
        ['name' => 'Tabs operations', 'category' => 'operational', 'is_active' => true],
    );
    $department = Department::query()->create([
        'company_id' => $companyId, 'department_type_id' => $type->id, 'status' => 'active',
    ]);
    $head = Employee::factory()->create([
        'company_id' => $companyId, 'department_id' => $department->id,
        'full_name' => 'Tabs Head', 'status' => 'active', 'employee_type' => 'full_time',
    ]);
    $department->update(['head_id' => $head->id]);
    $hod = User::factory()->create(['company_id' => $companyId, 'employee_id' => $head->id]);
    tabsRole($hod, 'people_hod');
    EmployeePortalAccess::query()->create([
        'employee_id' => $head->id, 'user_id' => $hod->id,
        'display_name' => 'Tabs Head', 'status' => EmployeePortalAccess::STATUS_ACTIVE,
    ]);

    // A second department whose head is somebody else: an open checkpoint here
    // is open for the company and not for the fixture's HOD, which is the pair
    // the two empty states have to tell apart.
    // A company may hold one department per type, so the second one needs its
    // own type rather than a second row of the first.
    $otherType = DepartmentType::query()->firstOrCreate(
        ['code' => 'tabs-support'],
        ['name' => 'Tabs support', 'category' => 'operational', 'is_active' => true],
    );
    $otherDepartment = Department::query()->create([
        'company_id' => $companyId, 'department_type_id' => $otherType->id, 'status' => 'active',
    ]);
    $otherHead = Employee::factory()->create([
        'company_id' => $companyId, 'department_id' => $otherDepartment->id,
        'full_name' => 'Other Head', 'status' => 'active', 'employee_type' => 'full_time',
    ]);
    $otherDepartment->update(['head_id' => $otherHead->id]);
    $otherHod = User::factory()->create(['company_id' => $companyId, 'employee_id' => $otherHead->id]);
    tabsRole($otherHod, 'people_hod');
    EmployeePortalAccess::query()->create([
        'employee_id' => $otherHead->id, 'user_id' => $otherHod->id,
        'display_name' => 'Other Head', 'status' => EmployeePortalAccess::STATUS_ACTIVE,
    ]);

    $hr = User::factory()->create(['company_id' => $companyId]);
    tabsRole($hr, 'people_hr');
    $employeeUser = User::factory()->create(['company_id' => $companyId]);
    tabsRole($employeeUser, 'people_employee');
    $trainer = NativeWorkforceFixture::create($tenantId, WorkforceResourceType::Employee, $companyId);

    return compact(
        'tenantId', 'companyId', 'company', 'hod', 'otherHod', 'hr', 'employeeUser',
        'department', 'otherDepartment', 'trainer',
    );
}

/** An attended event that ended long enough ago to have opened a checkpoint. */
function tabsOpenCheckpointFor(array $f, Department $department): void
{
    $catalog = app(SkillCatalogStore::class);
    $category = $catalog->defineCategory($f['companyId'], Str::lower(Str::random(12)), 'Tabs');
    $skill = $catalog->defineSkill($f['companyId'], new SkillDraft(
        code: Str::lower(Str::random(12)), name: 'Tabs skill '.Str::random(4),
        definition: 'Applied after training.', categoryId: (int) $category->id,
    ));
    $course = app(TrainingCatalogStore::class)->defineCourse($f['companyId'], new TrainingCourseDraft(
        code: Str::lower(Str::random(12)), title: 'Tabs course '.Str::random(4),
        deliveryMode: DeliveryMode::InternalClassroom, skillIds: [(int) $skill->id],
        internalTrainerEmployeeEntityId: (int) $f['trainer']->id,
    ));

    // The store refuses an event ending in the past, so schedule ahead and move
    // the row back afterwards.
    $event = app(TrainingEventStore::class)->schedule($f['companyId'], new TrainingEventDraft(
        courseId: (int) $course->id, startsAt: now()->addDay(), endsAt: now()->addDay()->addHours(4),
        capacity: 50, organizerEmployeeEntityId: (int) $f['trainer']->id,
    ));
    $event->forceFill([
        'starts_at' => now()->subDays(35)->subHours(4),
        'ends_at' => now()->subDays(35),
    ])->save();

    $employee = Employee::factory()->create([
        'company_id' => $f['companyId'], 'department_id' => $department->id,
        'full_name' => 'Attendee '.Str::random(4), 'status' => 'active', 'employee_type' => 'full_time',
    ]);
    $store = app(TrainingParticipationStore::class);
    Carbon::setTestNow($event->ends_at->copy()->subHours(4));
    $session = $store->defineSession(
        $f['hr'], $f['companyId'], (int) $event->id,
        (string) Str::uuid(), $event->starts_at, $event->ends_at,
    );
    Carbon::setTestNow($event->ends_at->copy()->addHour());
    $store->recordAttendance($f['hr'], $f['companyId'], (int) $session->id, new WorkforceSubject(
        $f['tenantId'], $f['companyId'], WorkforceResourceType::Employee, (string) $employee->id,
        new ExternalReference(WorkforceResourceType::Employee, (string) $employee->id),
    ), new ParticipationFactDraft(
        attendance: AttendanceStatus::Present, actualMinutes: 240,
        source: 'manual', sourceReference: (string) Str::uuid(),
    ));
    Carbon::setTestNow();
}

test('the hub sends each actor to the section they already hold and refuses one who holds neither', function (): void {
    $f = tabsFixture();

    test()->actingAs($f['hod'])
        ->get(route('people.training.effectiveness.hub'))
        ->assertRedirect(route('people.training.effectiveness.index'));

    test()->actingAs($f['hr'])
        ->get(route('people.training.effectiveness.hub'))
        ->assertRedirect(route('people.training.effectiveness.summary'));

    // The combination grants nothing: an actor with neither capability is
    // refused at the hub exactly as they are at both destinations.
    test()->actingAs($f['employeeUser'])
        ->get(route('people.training.effectiveness.hub'))
        ->assertForbidden();
    test()->actingAs($f['employeeUser'])
        ->get(route('people.training.effectiveness.index'))
        ->assertForbidden();
    test()->actingAs($f['employeeUser'])
        ->get(route('people.training.effectiveness.summary'))
        ->assertForbidden();
});

test('a tab is offered only to an actor who already holds that section', function (): void {
    $f = tabsFixture();

    // HOD: review only. No Summary tab, and the strip itself stays away rather
    // than rendering a single tab that goes nowhere else.
    $review = Livewire::actingAs($f['hod'])->test(ReviewIndex::class)->assertOk();
    expect($review->html())->not->toContain('data-testid="effectiveness-tabs"')
        ->and($review->html())->not->toContain(route('people.training.effectiveness.summary'));

    // HR: summary only.
    $summary = Livewire::actingAs($f['hr'])->test(AggregateIndex::class)->assertOk();
    expect($summary->html())->not->toContain('data-testid="effectiveness-tabs"')
        ->and($summary->html())->not->toContain(route('people.training.effectiveness.index'));
});

test('an actor holding both sections gets both tabs with the current one marked', function (): void {
    $f = tabsFixture();
    tabsRole($f['hr'], 'people_hod');

    $summary = Livewire::actingAs($f['hr'])->test(AggregateIndex::class)->assertOk();
    $summaryHtml = html_entity_decode($summary->html());

    expect($summaryHtml)->toContain('data-testid="effectiveness-tabs"')
        ->and($summaryHtml)->toContain(route('people.training.effectiveness.index'))
        ->and($summaryHtml)->toContain('Training effectiveness sections');

    $review = Livewire::actingAs($f['hr'])->test(ReviewIndex::class)->assertOk();
    $reviewHtml = html_entity_decode($review->html());

    expect($reviewHtml)->toContain('data-testid="effectiveness-tabs"')
        ->and($reviewHtml)->toContain(route('people.training.effectiveness.summary'));

    // aria-current marks the section being read, on each page in turn.
    expect(tabsCurrentHref($summaryHtml))->toBe(route('people.training.effectiveness.summary'));
    expect(tabsCurrentHref($reviewHtml))->toBe(route('people.training.effectiveness.index'));
});

/** The href of the link carrying aria-current="page". */
function tabsCurrentHref(string $html): ?string
{
    preg_match_all('/<a\s[^>]*>/', $html, $anchors);

    foreach ($anchors[0] as $anchor) {
        if (! str_contains($anchor, 'aria-current="page"')) {
            continue;
        }

        preg_match('/href="([^"]+)"/', $anchor, $href);

        return $href[1] ?? null;
    }

    return null;
}

test('one menu entry replaces the two, and it is offered to each section holder but not to an employee', function (): void {
    $f = tabsFixture();

    $items = collect(require dirname(__DIR__, 2).'/Config/menu.php')
        ->get('items');
    $effectiveness = collect($items)->filter(
        static fn (array $item): bool => str_contains((string) $item['id'], 'effectiveness'),
    )->values();

    expect($effectiveness)->toHaveCount(1);
    expect($effectiveness->first())->toMatchArray([
        'id' => 'people.training-effectiveness',
        'route' => 'people.training.effectiveness.hub',
        'condition' => 'people.training.effectiveness-audience',
    ]);
    // A single 'permission' key would hide the area from whoever holds the
    // other section, which is the trap this consolidation has to avoid.
    expect($effectiveness->first())->not->toHaveKey('permission');

    $registry = app(MenuConditionRegistry::class);
    expect($registry->allows('people.training.effectiveness-audience', $f['hod']))->toBeTrue();
    expect($registry->allows('people.training.effectiveness-audience', $f['hr']))->toBeTrue();
    expect($registry->allows('people.training.effectiveness-audience', $f['employeeUser']))->toBeFalse();
});

test('review distinguishes a company with no open checkpoint from one where the open questions are another head\'s', function (): void {
    $f = tabsFixture();

    Livewire::actingAs($f['hod'])->test(ReviewIndex::class)
        ->assertOk()
        ->assertSee('No attended training has reached a checkpoint yet');

    tabsOpenCheckpointFor($f, $f['otherDepartment']);

    Livewire::actingAs($f['hod'])->test(ReviewIndex::class)
        ->assertOk()
        ->assertSee('No effectiveness question is open for your department')
        ->assertDontSee('No attended training has reached a checkpoint yet');

    // And the head it was assigned to does see it, so the message above is
    // describing the queue rather than hiding a row from its owner.
    Livewire::actingAs($f['otherHod'])->test(ReviewIndex::class)
        ->assertOk()
        ->assertDontSee('No effectiveness question is open for your department');
});
