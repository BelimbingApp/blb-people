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
use App\Domains\People\Settings\Models\EmployeePortalAccess;
use App\Domains\People\Settings\Models\EmployeeWorkProfile;
use App\Domains\People\Settings\Models\PeopleReferenceEntry;
use App\Domains\People\Skills\Data\SkillDraft;
use App\Domains\People\Skills\Enums\AssessmentMethod;
use App\Domains\People\Skills\Services\SkillAudienceAssignmentStore;
use App\Domains\People\Skills\Services\SkillCatalogStore;
use App\Domains\People\Training\Contracts\SummarizesTrainingParticipation;
use App\Domains\People\Training\Data\TrainingCourseDraft;
use App\Domains\People\Training\Data\TrainingEventDraft;
use App\Domains\People\Training\Enums\DeliveryMode;
use App\Domains\People\Training\Enums\TrainingEventStatus;
use App\Domains\People\Training\Exceptions\InvalidTrainingCatalogException;
use App\Domains\People\Training\Exceptions\InvalidTrainingEventException;
use App\Domains\People\Training\Exceptions\TrainingEventNotFoundException;
use App\Domains\People\Training\Livewire\Catalog\Index as CatalogIndex;
use App\Domains\People\Training\Livewire\Event\Index;
use App\Domains\People\Training\Models\TrainingCourse;
use App\Domains\People\Training\Models\TrainingEventAuditEvent;
use App\Domains\People\Training\Services\TrainingAudience;
use App\Domains\People\Training\Services\TrainingCatalogStore;
use App\Domains\People\Training\Services\TrainingEventStore;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;

afterEach(function (): void {
    $this->travelBack();
    app(TenantContext::class)->clear();
});

/**
 * Native workforce fixtures. A workforce subject is a People record now: the
 * company is a Core company, a department is a People reference entry, and an
 * employee is a Core employee whose work profile names its unit. That is what
 * NativeWorkforceDirectory reads back, so building anything else would test a
 * shape the seam never sees.
 */
function trainingEventCompany(int $tenantId, string $name = 'Sibling Training Company'): Company
{
    return Company::factory()->create(['tenant_id' => $tenantId, 'name' => $name, 'status' => 'active']);
}

function trainingEventDepartment(int $companyId, string $name, string $code): PeopleReferenceEntry
{
    return PeopleReferenceEntry::query()->create([
        'company_id' => $companyId,
        'type' => PeopleReferenceEntry::TYPE_ORGANIZATION_UNIT,
        'code' => $code,
        'name' => $name,
        'status' => PeopleReferenceEntry::STATUS_ACTIVE,
    ]);
}

function trainingEventEmployee(
    int $companyId,
    string $name,
    ?int $departmentEntryId = null,
    ?int $coreDepartmentId = null,
): Employee {
    $employee = Employee::factory()->create([
        'company_id' => $companyId,
        'department_id' => $coreDepartmentId,
        'full_name' => $name,
        'status' => 'active',
        'employee_type' => 'full_time',
    ]);

    if ($departmentEntryId !== null) {
        EmployeeWorkProfile::query()->create([
            'employee_id' => $employee->id,
            'organization_unit_id' => $departmentEntryId,
        ]);
    }

    return $employee;
}

/** @return array<string, mixed> */
function trainingEventFixture(): array
{
    [$tenant, $platformCompany] = createTenantWithCompany(
        ['name' => 'Training Event Tenant'],
        ['name' => 'Training Event Platform Company'],
    );
    $tenantId = (int) $tenant->id;
    app(TenantContext::class)->set($tenantId);
    // The workforce company *is* the platform company after the relocation.
    $company = $platformCompany;

    $departmentEntries = [
        trainingEventDepartment((int) $company->id, 'Operations', 'OPS'),
        trainingEventDepartment((int) $company->id, 'Finance', 'FIN'),
    ];
    $departments = array_map(static fn ($entry): int => (int) $entry->getKey(), $departmentEntries);

    // A Core department carries the head relationship the seam reads back as
    // departmentHeadReference; the reference entry carries the unit identity.
    $departmentType = DepartmentType::query()->create([
        'code' => 'training-operations',
        'name' => 'Operations',
        'category' => 'operational',
        'is_active' => true,
    ]);
    $coreDepartment = Department::query()->create([
        'company_id' => $company->id,
        'department_type_id' => $departmentType->id,
        'status' => 'active',
    ]);

    $head = trainingEventEmployee((int) $company->id, 'Operations Head', $departments[0], (int) $coreDepartment->id);
    $coreDepartment->update(['head_id' => $head->id]);
    $operations = trainingEventEmployee((int) $company->id, 'Operations Worker', $departments[0], (int) $coreDepartment->id);
    $operations->update(['supervisor_id' => $head->id]);
    $finance = trainingEventEmployee((int) $company->id, 'Finance Worker', $departments[1]);
    $trainer = trainingEventEmployee((int) $company->id, 'Trainer', $departments[0], (int) $coreDepartment->id);

    $category = app(SkillCatalogStore::class)->defineCategory((int) $company->id, 'safety', 'Safety');
    $skill = app(SkillCatalogStore::class)->defineSkill((int) $company->id, new SkillDraft(
        code: 'forklift.operation',
        name: 'Forklift operation',
        definition: 'Operate a forklift safely.',
        categoryId: (int) $category->id,
        defaultAssessmentMethod: AssessmentMethod::DirectObservation,
    ));
    $course = app(TrainingCatalogStore::class)->defineCourse((int) $company->id, new TrainingCourseDraft(
        code: 'forklift.induction',
        title: 'Forklift induction',
        deliveryMode: DeliveryMode::InternalClassroom,
        skillIds: [(int) $skill->id],
        internalTrainerEmployeeEntityId: (int) $trainer->id,
    ));

    return compact('tenant', 'platformCompany', 'tenantId', 'company', 'departments',
        'head', 'operations', 'finance', 'trainer', 'course');
}

function trainingEventDraft(array $fixture, array $overrides = []): TrainingEventDraft
{
    return new TrainingEventDraft(...array_merge([
        'courseId' => (int) $fixture['course']->id,
        'startsAt' => new DateTimeImmutable('2026-10-01T09:00:00+00:00'),
        'endsAt' => new DateTimeImmutable('2026-10-01T17:00:00+00:00'),
        'capacity' => 20,
        'organizerEmployeeEntityId' => (int) $fixture['operations']->id,
        'targetDepartmentEntityId' => $fixture['departments'][0],
    ], $overrides));
}

/**
 * A HOD is a platform user bound to a native employee: the portal-access row
 * is what the seam's employeeForUser() reads back, and the confirmed actor
 * binding is what SkillAudience's HOD scope requires. Same recipe as R2's
 * development-action tests.
 */
function trainingEventBindHod(User $hr, User $hod, array $fixture, string $reviewReference): void
{
    $head = $fixture['head'];
    $hod->update(['employee_id' => $head->id]);
    EmployeePortalAccess::query()->updateOrCreate(
        ['employee_id' => $head->id],
        ['user_id' => $hod->id, 'display_name' => $head->displayName(), 'status' => EmployeePortalAccess::STATUS_ACTIVE],
    );
    app(SkillAudienceAssignmentStore::class)->confirmActor($hr, $hod, (int) $fixture['company']->id, (int) $head->id, $reviewReference);
}

function trainingEventRole(User $user, string $code): void
{
    setupAuthzRoles();
    $role = Role::query()->whereNull('company_id')->where('code', $code)->sole();
    PrincipalRole::query()->create([
        'company_id' => $user->company_id,
        'principal_type' => PrincipalType::USER->value,
        'principal_id' => $user->id,
        'role_id' => $role->id,
    ]);
}

test('training events preserve schedule snapshots and terminal audit history', function (): void {
    $this->travelTo(new DateTimeImmutable('2026-09-30T12:00:00+00:00'));
    $fixture = trainingEventFixture();
    $store = app(TrainingEventStore::class);
    $event = $store->schedule((int) $fixture['company']->id, trainingEventDraft($fixture), actorUserId: 41,
        actorEmployeeEntityId: (int) $fixture['operations']->id);

    expect($event->course_code_snapshot)->toBe('forklift.induction')
        ->and($event->course_title_snapshot)->toBe('Forklift induction')
        ->and($event->delivery_mode_snapshot)->toBe(DeliveryMode::InternalClassroom)
        ->and($event->status)->toBe(TrainingEventStatus::Scheduled)
        ->and($event->internal_trainer_employee_entity_id)->toBe((int) $fixture['trainer']->id);

    $revised = $store->revise((int) $fixture['company']->id, (int) $event->id,
        trainingEventDraft($fixture, ['capacity' => 25, 'venue' => 'Training room']), 41);
    $this->travelTo(new DateTimeImmutable('2026-10-01T09:00:00+00:00'));
    $store->start((int) $fixture['company']->id, (int) $event->id, 41);
    $this->travelTo(new DateTimeImmutable('2026-10-01T17:00:00+00:00'));
    $completed = $store->complete((int) $fixture['company']->id, (int) $event->id, 'Signed facilitator report', 41);

    expect($revised->capacity)->toBe(25)
        ->and($completed->status)->toBe(TrainingEventStatus::Completed)
        ->and($completed->completion_evidence)->toBe('Signed facilitator report')
        ->and($store->registerQuery((int) $fixture['company']->id)->pluck('id')->all())->toBe([(int) $event->id])
        ->and(TrainingEventAuditEvent::query()->forCompany($fixture['tenantId'], (int) $fixture['company']->id)->count())->toBe(4)
        ->and(app(SummarizesTrainingParticipation::class)->forEvents((int) $fixture['company']->id, [(int) $event->id]))->toBe([]);

    $audit = TrainingEventAuditEvent::query()->forCompany($fixture['tenantId'], (int) $fixture['company']->id)->firstOrFail();
    expect(fn () => $audit->update(['comment' => 'rewrite']))
        ->toThrow(InvalidTrainingEventException::class)
        ->and(fn () => DB::transaction(fn () => DB::table('people_connector_training_event_audit_events')->where('id', $audit->id)->update(['comment' => 'rewrite'])))
        ->toThrow(QueryException::class)
        ->and(fn () => DB::transaction(fn () => DB::table('people_connector_training_event_audit_events')->where('id', $audit->id)->delete()))
        ->toThrow(QueryException::class);
});

test('HR can maintain the company-scoped course catalog without exposing a course to another company', function (): void {
    $fixture = trainingEventFixture();
    $hr = User::factory()->create(['company_id' => $fixture['platformCompany']->id]);
    trainingEventRole($hr, 'people_hr');

    Livewire::actingAs($hr)->test(CatalogIndex::class)
        ->call('startCourse')
        ->set('courseForm.code', 'confined.space')
        ->set('courseForm.title', 'Confined space entry')
        ->set('courseForm.delivery_mode', DeliveryMode::InternalOjt->value)
        ->set('courseForm.skill_ids', [(int) $fixture['course']->skillIds()[0]])
        ->set('courseForm.internal_trainer_employee_entity_id', (int) $fixture['trainer']->id)
        ->call('saveCourse')
        ->assertHasNoErrors()
        ->assertSee('Confined space entry');

    $saved = TrainingCourse::query()->forCompany($fixture['tenantId'], (int) $fixture['company']->id)
        ->where('code', 'confined.space')->sole();
    expect($saved->mappedSkills()->pluck('id')->all())->toBe([(int) $fixture['course']->skillIds()[0]]);

    $sibling = trainingEventCompany($fixture['tenantId']);
    expect(TrainingCourse::query()->forCompany($fixture['tenantId'], (int) $sibling->id)->where('code', 'confined.space')->exists())
        ->toBeFalse();
});

test('catalog rejects a sibling-company trainer at the store boundary', function (): void {
    $fixture = trainingEventFixture();
    $sibling = trainingEventCompany($fixture['tenantId']);
    $siblingTrainer = trainingEventEmployee((int) $sibling->id, 'Sibling trainer');

    expect(fn () => app(TrainingCatalogStore::class)->defineCourse((int) $fixture['company']->id, new TrainingCourseDraft(
        code: 'cross-company-trainer', title: 'Cross company trainer', deliveryMode: DeliveryMode::Coaching,
        skillIds: [(int) $fixture['course']->skillIds()[0]], internalTrainerEmployeeEntityId: (int) $siblingTrainer->id,
        // As in TrainingCatalogStoreTest: the seam resolves employees per company,
        // so a sibling company's trainer is unknown here and the existence check
        // refuses it first.
    )))->toThrow(InvalidTrainingCatalogException::class, 'employee workforce entity');
});

test('a HOD cannot reveal catalog management state or invoke catalog mutations', function (): void {
    $fixture = trainingEventFixture();
    $hr = User::factory()->create(['company_id' => $fixture['platformCompany']->id]);
    $hod = User::factory()->create(['company_id' => $fixture['platformCompany']->id]);
    trainingEventRole($hr, 'people_hr');
    trainingEventRole($hod, 'people_hod');
    trainingEventBindHod($hr, $hod, $fixture, 'review:training-catalog-hod');

    Livewire::actingAs($hod)->test(CatalogIndex::class)
        ->set('courseForm', ['code' => 'forced.course'])
        ->assertDontSee('New course')
        ->assertDontSee('Define course')
        ->assertDontSee('Operations Worker')
        ->assertSee('Forklift induction');

    expect(fn () => Livewire::actingAs($hod)->test(CatalogIndex::class)->call('startCourse'))
        ->toThrow(AuthorizationDeniedException::class)
        ->and(fn () => Livewire::actingAs($hod)->test(CatalogIndex::class)->call('saveCourse'))
        ->toThrow(AuthorizationDeniedException::class)
        ->and(fn () => Livewire::actingAs($hod)->test(CatalogIndex::class)->call('toggleCourseActive', (int) $fixture['course']->id))
        ->toThrow(AuthorizationDeniedException::class);
});

test('skill and training catalog routes resolve their distinct Livewire components', function (): void {
    $fixture = trainingEventFixture();
    $hr = User::factory()->create(['company_id' => $fixture['platformCompany']->id]);
    trainingEventRole($hr, 'people_hr');
    $this->withoutVite();

    $this->actingAs($hr)->get(route('people.skill.catalog.index'))->assertOk();
    $this->actingAs($hr)->get(route('people.training.catalog.index'))->assertOk();
});

test('event schedule and transitions obey the event clock at the store boundary', function (): void {
    $this->travelTo(new DateTimeImmutable('2026-09-30T12:00:00+00:00'));
    $fixture = trainingEventFixture();
    $store = app(TrainingEventStore::class);

    expect(fn () => $store->schedule((int) $fixture['company']->id, trainingEventDraft($fixture, [
        'startsAt' => new DateTimeImmutable('2026-09-29T09:00:00+00:00'),
        'endsAt' => new DateTimeImmutable('2026-09-29T17:00:00+00:00'),
    ])))->toThrow(InvalidTrainingEventException::class, 'must end in the future');

    $event = $store->schedule((int) $fixture['company']->id, trainingEventDraft($fixture));
    $neverStarted = $store->schedule((int) $fixture['company']->id, trainingEventDraft($fixture));
    expect(fn () => $store->revise((int) $fixture['company']->id, (int) $event->id, trainingEventDraft($fixture, [
        'startsAt' => new DateTimeImmutable('2026-09-29T09:00:00+00:00'),
        'endsAt' => new DateTimeImmutable('2026-09-29T17:00:00+00:00'),
    ])))->toThrow(InvalidTrainingEventException::class, 'must end in the future');

    $this->travelTo(new DateTimeImmutable('2026-10-01T08:59:59+00:00'));
    expect(fn () => $store->start((int) $fixture['company']->id, (int) $event->id))
        ->toThrow(InvalidTrainingEventException::class, 'before its scheduled start');

    $this->travelTo(new DateTimeImmutable('2026-10-01T09:00:00+00:00'));
    expect($store->start((int) $fixture['company']->id, (int) $event->id)->status)
        ->toBe(TrainingEventStatus::InProgress);

    $this->travelTo(new DateTimeImmutable('2026-10-01T16:59:59+00:00'));
    expect(fn () => $store->complete((int) $fixture['company']->id, (int) $event->id, 'Too early'))
        ->toThrow(InvalidTrainingEventException::class, 'before its scheduled end');

    $this->travelTo(new DateTimeImmutable('2026-10-01T17:00:00+00:00'));
    expect($store->complete((int) $fixture['company']->id, (int) $event->id, 'Signed report')->status)
        ->toBe(TrainingEventStatus::Completed)
        ->and(fn () => $store->start((int) $fixture['company']->id, (int) $neverStarted->id))
        ->toThrow(InvalidTrainingEventException::class, 'after its scheduled end');
});

test('livewire reports future transition attempts without falsifying event history', function (): void {
    $this->travelTo(new DateTimeImmutable('2026-09-30T12:00:00+00:00'));
    $fixture = trainingEventFixture();
    $store = app(TrainingEventStore::class);
    $event = $store->schedule((int) $fixture['company']->id, trainingEventDraft($fixture));
    $hr = User::factory()->create(['company_id' => $fixture['platformCompany']->id]);
    trainingEventRole($hr, 'people_hr');

    Livewire::actingAs($hr)->test(Index::class)
        ->set('courseId', (int) $fixture['course']->id)
        ->set('organizerEmployeeEntityId', (int) $fixture['operations']->id)
        ->set('startsAt', '2026-09-29T09:00')
        ->set('endsAt', '2026-09-29T17:00')
        ->call('save')
        ->assertHasErrors('event')
        ->assertSee('The event must end in the future.');

    Livewire::actingAs($hr)->test(Index::class)
        ->call('start', (int) $event->id)
        ->assertHasErrors('event')
        ->assertSee('The event cannot start before its scheduled start.');

    $this->travelTo(new DateTimeImmutable('2026-10-01T09:00:00+00:00'));
    $store->start((int) $fixture['company']->id, (int) $event->id);
    Livewire::actingAs($hr)->test(Index::class)
        ->set("evidence.{$event->id}", 'Premature report')
        ->call('complete', (int) $event->id)
        ->assertHasErrors('event')
        ->assertSee('The event cannot be completed before its scheduled end.');

    expect($event->refresh()->status)->toBe(TrainingEventStatus::InProgress)
        ->and(TrainingEventAuditEvent::query()
            ->forCompany($fixture['tenantId'], (int) $fixture['company']->id)
            ->where('training_event_id', $event->id)->pluck('event_type')->all())
        ->toBe(['scheduled', 'started']);
});

test('event invariants and sibling company or tenant access fail closed', function (): void {
    $fixture = trainingEventFixture();
    $store = app(TrainingEventStore::class);

    expect(fn () => $store->schedule((int) $fixture['company']->id, trainingEventDraft($fixture, [
        'endsAt' => new DateTimeImmutable('2026-10-01T08:59:00+00:00'),
    ])))->toThrow(InvalidTrainingEventException::class, 'end must be after')
        ->and(fn () => $store->schedule((int) $fixture['company']->id, trainingEventDraft($fixture, ['capacity' => 0])))
        ->toThrow(InvalidTrainingEventException::class, 'Capacity');

    $siblingCompany = trainingEventCompany($fixture['tenantId']);
    expect(fn () => $store->schedule((int) $siblingCompany->id, trainingEventDraft($fixture)))
        ->toThrow(InvalidTrainingEventException::class, 'active training course');

    expect(fn () => DB::transaction(fn () => DB::table('people_connector_training_events')->insert([
        'tenant_id' => $fixture['tenantId'],
        'company_entity_id' => $siblingCompany->id,
        'event_key' => (string) Str::uuid(),
        'course_id' => $fixture['course']->id,
        'course_code_snapshot' => $fixture['course']->code,
        'course_title_snapshot' => $fixture['course']->title,
        'delivery_mode_snapshot' => DeliveryMode::InternalClassroom->value,
        'organizer_employee_entity_id' => $fixture['operations']->id,
        'starts_at' => now()->addDay(),
        'ends_at' => now()->addDay()->addHour(),
        'capacity' => 1,
        'status' => TrainingEventStatus::Scheduled->value,
        'created_at' => now(),
        'updated_at' => now(),
    ])))->toThrow(QueryException::class);

    $event = $store->schedule((int) $fixture['company']->id, trainingEventDraft($fixture));
    expect(fn () => $store->start((int) $siblingCompany->id, (int) $event->id))
        ->toThrow(TrainingEventNotFoundException::class);

    $otherTenant = createTenant(['name' => 'Other Training Tenant']);
    app(TenantContext::class)->set((int) $otherTenant->id);
    expect(fn () => $store->start((int) $fixture['company']->id, (int) $event->id))
        ->toThrow(TrainingEventNotFoundException::class);
});

test('the actual register gives HR company scope, HOD department scope, and rejects grant all', function (): void {
    $fixture = trainingEventFixture();
    $store = app(TrainingEventStore::class);
    $operationsEvent = $store->schedule((int) $fixture['company']->id, trainingEventDraft($fixture));
    $financeEvent = $store->schedule((int) $fixture['company']->id, trainingEventDraft($fixture, [
        'targetDepartmentEntityId' => $fixture['departments'][1],
        'organizerEmployeeEntityId' => (int) $fixture['finance']->id,
        'venue' => 'Finance room',
    ]));
    $companyWideEvent = $store->schedule((int) $fixture['company']->id, trainingEventDraft($fixture, [
        'targetDepartmentEntityId' => null,
        'venue' => 'Company hall',
    ]));

    $hr = User::factory()->create(['company_id' => $fixture['platformCompany']->id]);
    $hod = User::factory()->create(['company_id' => $fixture['platformCompany']->id]);
    $platformAdmin = User::factory()->create(['company_id' => $fixture['platformCompany']->id]);
    trainingEventRole($hr, 'people_hr');
    trainingEventRole($hod, 'people_hod');
    trainingEventRole($platformAdmin, 'core_admin');
    trainingEventBindHod($hr, $hod, $fixture, 'review:training-hod');

    $this->actingAs($platformAdmin)
        ->get(route('people.training.events.index'))
        ->assertForbidden();

    expect(app(TrainingAudience::class)->visibleEvents($hr, (int) $fixture['company']->id)->pluck('id')->all())
        ->toEqualCanonicalizing([(int) $operationsEvent->id, (int) $financeEvent->id, (int) $companyWideEvent->id])
        ->and(app(TrainingAudience::class)->visibleEvents($hod, (int) $fixture['company']->id)->pluck('id')->all())
        ->toEqualCanonicalizing([(int) $operationsEvent->id, (int) $companyWideEvent->id])
        ->and(app(TrainingAudience::class)->canManage($hod, (int) $fixture['company']->id))->toBeFalse()
        ->and(fn () => app(TrainingAudience::class)->allowedCompanies($platformAdmin))
        ->toThrow(AuthorizationDeniedException::class);

    Livewire::actingAs($hod)->test(Index::class)
        ->assertViewHas('events', fn ($events): bool => $events->pluck('id')->all() === [(int) $operationsEvent->id, (int) $companyWideEvent->id])
        ->assertDontSee('Finance Worker')
        ->assertDontSee('Finance room')
        ->assertSee('Company hall')
        ->assertSee('Company-wide')
        ->assertSee('Not recorded by the participant register yet');

    expect(fn () => Livewire::actingAs($hod)->test(Index::class)->call('start', (int) $operationsEvent->id))
        ->toThrow(AuthorizationDeniedException::class);

    $store->cancel((int) $fixture['company']->id, (int) $operationsEvent->id, 'Weather closure');
    Livewire::actingAs($hr)->test(Index::class)
        ->assertSee('Cancelled')
        ->assertSee('Weather closure');

});

test('catalog selectCompany switches to an attributable company and refuses an unknown company', function (): void {
    $this->withoutVite();
    $fixture = trainingEventFixture();
    $hr = User::factory()->create(['company_id' => $fixture['platformCompany']->id]);
    trainingEventRole($hr, 'people_hr');

    // Native attribution: a user may act for exactly one company, the platform
    // company they belong to (CompanyAttribution::allowedCompanyEntities). A
    // sibling company in the same tenant is therefore not selectable, which the
    // connector's projection-based attribution allowed; the discard-on-select
    // behaviour is proven on the one attributable company instead.
    $siblingCompany = trainingEventCompany($fixture['tenantId'], 'Second Training Company');

    Livewire::actingAs($hr)->test(CatalogIndex::class)
        ->set('editingCourseId', (int) $fixture['course']->id)
        ->set('courseForm', ['title' => 'Discard me'])
        ->call('selectCompany', (int) $fixture['company']->id)
        ->assertSet('companyEntityId', (int) $fixture['company']->id)
        ->assertSet('editingCourseId', null)
        ->assertSet('courseForm', []);

    Livewire::actingAs($hr)->test(CatalogIndex::class)
        ->call('selectCompany', (int) $siblingCompany->id)
        ->assertStatus(404);

    Livewire::actingAs($hr)->test(CatalogIndex::class)
        ->call('selectCompany', PHP_INT_MAX)
        ->assertStatus(404);
});

test('catalog editCourse loads the company course and refuses a user without manage capability', function (): void {
    $fixture = trainingEventFixture();
    $hr = User::factory()->create(['company_id' => $fixture['platformCompany']->id]);
    $hod = User::factory()->create(['company_id' => $fixture['platformCompany']->id]);
    trainingEventRole($hr, 'people_hr');
    trainingEventRole($hod, 'people_hod');

    Livewire::actingAs($hr)->test(CatalogIndex::class)
        ->call('editCourse', (int) $fixture['course']->id)
        ->assertSet('editingCourseId', (int) $fixture['course']->id)
        ->assertSet('courseForm.code', 'forklift.induction')
        ->assertSet('courseForm.title', 'Forklift induction');

    expect(fn () => Livewire::actingAs($hod)->test(CatalogIndex::class)
        ->call('editCourse', (int) $fixture['course']->id))->toThrow(AuthorizationDeniedException::class);
});

test('catalog cancelCourse discards local editing state without mutating the course', function (): void {
    $fixture = trainingEventFixture();
    $hr = User::factory()->create(['company_id' => $fixture['platformCompany']->id]);
    trainingEventRole($hr, 'people_hr');

    Livewire::actingAs($hr)->test(CatalogIndex::class)
        ->call('editCourse', (int) $fixture['course']->id)
        ->set('courseForm.title', 'Unsaved title')
        ->call('cancelCourse')
        ->assertSet('editingCourseId', null)
        ->assertSet('courseForm', []);

    expect($fixture['course']->refresh()->title)->toBe('Forklift induction');
});

test('event selectCompany switches to an attributable company and refuses an unknown company', function (): void {
    $this->withoutVite();
    $fixture = trainingEventFixture();
    $hr = User::factory()->create(['company_id' => $fixture['platformCompany']->id]);
    trainingEventRole($hr, 'people_hr');

    // Same native-attribution rule as the catalog test above.
    $siblingCompany = trainingEventCompany($fixture['tenantId'], 'Second Event Company');

    Livewire::actingAs($hr)->test(Index::class)
        ->set('editingEventId', 99)
        ->set('venue', 'Discard me')
        ->call('selectCompany', (int) $fixture['company']->id)
        ->assertSet('companyEntityId', (int) $fixture['company']->id)
        ->assertSet('editingEventId', null)
        ->assertSet('venue', '');

    Livewire::actingAs($hr)->test(Index::class)
        ->call('selectCompany', (int) $siblingCompany->id)
        ->assertStatus(404);

    Livewire::actingAs($hr)->test(Index::class)
        ->call('selectCompany', PHP_INT_MAX)
        ->assertStatus(404);
});

test('event editEvent loads a scheduled event and refuses users without manage capability or a non-scheduled event', function (): void {
    $this->withoutVite();
    $fixture = trainingEventFixture();
    $store = app(TrainingEventStore::class);
    $event = $store->schedule((int) $fixture['company']->id, trainingEventDraft($fixture, ['venue' => 'Workshop A']));
    $hr = User::factory()->create(['company_id' => $fixture['platformCompany']->id]);
    $hod = User::factory()->create(['company_id' => $fixture['platformCompany']->id]);
    trainingEventRole($hr, 'people_hr');
    trainingEventRole($hod, 'people_hod');

    Livewire::actingAs($hr)->test(Index::class)
        ->call('editEvent', (int) $event->id)
        ->assertSet('editingEventId', (int) $event->id)
        ->assertSet('courseId', (int) $fixture['course']->id)
        ->assertSet('venue', 'Workshop A');

    expect(fn () => Livewire::actingAs($hod)->test(Index::class)
        ->call('editEvent', (int) $event->id))->toThrow(AuthorizationDeniedException::class);

    $this->travelTo(new DateTimeImmutable('2026-10-01T09:00:00+00:00'));
    $store->start((int) $fixture['company']->id, (int) $event->id);
    Livewire::actingAs($hr)->test(Index::class)
        ->call('editEvent', (int) $event->id)
        ->assertStatus(409);
});

test('event cancel validates the reason, cancels for HR, and refuses a user without manage capability', function (): void {
    $fixture = trainingEventFixture();
    $store = app(TrainingEventStore::class);
    $event = $store->schedule((int) $fixture['company']->id, trainingEventDraft($fixture));
    $deniedEvent = $store->schedule((int) $fixture['company']->id, trainingEventDraft($fixture));
    $hr = User::factory()->create(['company_id' => $fixture['platformCompany']->id]);
    $hod = User::factory()->create(['company_id' => $fixture['platformCompany']->id]);
    trainingEventRole($hr, 'people_hr');
    trainingEventRole($hod, 'people_hod');

    Livewire::actingAs($hr)->test(Index::class)
        ->call('cancel', (int) $event->id)
        ->assertHasErrors('event');
    expect($event->refresh()->status)->toBe(TrainingEventStatus::Scheduled);

    Livewire::actingAs($hr)->test(Index::class)
        ->set("reason.{$event->id}", 'Site closure')
        ->call('cancel', (int) $event->id)
        ->assertHasNoErrors()
        ->assertSet("reason.{$event->id}", null);
    expect($event->refresh()->status)->toBe(TrainingEventStatus::Cancelled)
        ->and($event->cancellation_reason)->toBe('Site closure');

    expect(fn () => Livewire::actingAs($hod)->test(Index::class)
        ->set("reason.{$deniedEvent->id}", 'Not authorized')
        ->call('cancel', (int) $deniedEvent->id))->toThrow(AuthorizationDeniedException::class);
});

test('event addComment validates the note, records it for HR, and refuses a user without manage capability', function (): void {
    $fixture = trainingEventFixture();
    $event = app(TrainingEventStore::class)->schedule((int) $fixture['company']->id, trainingEventDraft($fixture));
    $hr = User::factory()->create(['company_id' => $fixture['platformCompany']->id]);
    $hod = User::factory()->create(['company_id' => $fixture['platformCompany']->id]);
    trainingEventRole($hr, 'people_hr');
    trainingEventRole($hod, 'people_hod');

    Livewire::actingAs($hr)->test(Index::class)
        ->call('addComment', (int) $event->id)
        ->assertHasErrors('event');
    expect(TrainingEventAuditEvent::query()
        ->forCompany($fixture['tenantId'], (int) $fixture['company']->id)
        ->where('training_event_id', $event->id)->count())->toBe(1);

    Livewire::actingAs($hr)->test(Index::class)
        ->set("comment.{$event->id}", 'Coordinator confirmed the room')
        ->call('addComment', (int) $event->id)
        ->assertHasNoErrors()
        ->assertSet("comment.{$event->id}", null);
    expect(TrainingEventAuditEvent::query()
        ->forCompany($fixture['tenantId'], (int) $fixture['company']->id)
        ->where('training_event_id', $event->id)
        ->where('event_type', 'commented')->where('comment', 'Coordinator confirmed the room')->exists())->toBeTrue();

    expect(fn () => Livewire::actingAs($hod)->test(Index::class)
        ->set("comment.{$event->id}", 'Not authorized')
        ->call('addComment', (int) $event->id))->toThrow(AuthorizationDeniedException::class);
});

test('event cancelEdit discards local editing state without mutating the event', function (): void {
    $fixture = trainingEventFixture();
    $event = app(TrainingEventStore::class)->schedule((int) $fixture['company']->id, trainingEventDraft($fixture, ['venue' => 'Original venue']));
    $hr = User::factory()->create(['company_id' => $fixture['platformCompany']->id]);
    trainingEventRole($hr, 'people_hr');

    Livewire::actingAs($hr)->test(Index::class)
        ->call('editEvent', (int) $event->id)
        ->set('venue', 'Unsaved venue')
        ->call('cancelEdit')
        ->assertSet('editingEventId', null)
        ->assertSet('venue', '');

    expect($event->refresh()->venue)->toBe('Original venue');
});
