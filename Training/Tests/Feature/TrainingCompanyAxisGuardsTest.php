<?php

use App\Base\Authz\Enums\PrincipalType;
use App\Base\Authz\Exceptions\AuthorizationDeniedException;
use App\Base\Authz\Models\PrincipalRole;
use App\Base\Authz\Models\Role;
use App\Base\Tenancy\Contracts\TenantContext;
use App\Core\Company\Models\Company;
use App\Core\Employee\Models\Employee;
use App\Core\User\Models\User;
use App\Domains\People\Settings\Models\PeopleReferenceEntry;
use App\Domains\People\Skills\Data\SkillDraft;
use App\Domains\People\Skills\Enums\AssessmentMethod;
use App\Domains\People\Skills\Services\SkillCatalogStore;
use App\Domains\People\Training\Data\TrainingCourseDraft;
use App\Domains\People\Training\Data\TrainingEventDraft;
use App\Domains\People\Training\Enums\DeliveryMode;
use App\Domains\People\Training\Exceptions\InvalidTrainingEventException;
use App\Domains\People\Training\Livewire\Catalog\Index as CatalogIndex;
use App\Domains\People\Training\Livewire\Event\Index as EventIndex;
use App\Domains\People\Training\Models\TrainingCourse;
use App\Domains\People\Training\Models\TrainingEvent;
use App\Domains\People\Training\Services\TrainingAudience;
use App\Domains\People\Training\Services\TrainingCatalogStore;
use App\Domains\People\Training\Services\TrainingEventStore;
use Livewire\Livewire;

/*
|--------------------------------------------------------------------------
| Company-axis guards of the relocated Training module
|--------------------------------------------------------------------------
|
| Two sibling companies in one tenant, each with its own department, employees,
| skill, course and scheduled event. Every test here is the failing test for one
| guard that the ported suite did not exercise when that guard was deleted:
| per-company code uniqueness, the seam's organizer / trainer / department
| checks, register and audience scoping, attribution in canManage, and the
| catalog page's own company boundary.
*/

beforeEach(function (): void {
    $this->withoutVite();
});

afterEach(function (): void {
    app(TenantContext::class)->clear();
});

/**
 * @return array{company: int, employees: list<int>, department: int, skill: int, course: TrainingCourse, event: TrainingEvent}
 */
function axisCompany(int $tenantId, ?Company $company, string $label): array
{
    $company ??= Company::factory()->create(['tenant_id' => $tenantId, 'name' => "$label Company", 'status' => 'active']);
    $companyId = (int) $company->id;

    $department = PeopleReferenceEntry::query()->create([
        'company_id' => $companyId,
        'type' => PeopleReferenceEntry::TYPE_ORGANIZATION_UNIT,
        'code' => 'ops-'.strtolower($label),
        'name' => "$label Operations",
        'status' => PeopleReferenceEntry::STATUS_ACTIVE,
    ]);

    $employees = [];
    foreach (['Organizer', 'Trainer'] as $role) {
        $employees[] = (int) Employee::factory()->create([
            'company_id' => $companyId,
            'full_name' => "$label $role",
            'status' => 'active',
            'employee_type' => 'full_time',
        ])->id;
    }

    $category = app(SkillCatalogStore::class)->defineCategory($companyId, 'safety', 'Safety');
    $skill = app(SkillCatalogStore::class)->defineSkill($companyId, new SkillDraft(
        code: 'forklift.operation',
        name: 'Forklift operation',
        definition: 'Operate a forklift safely.',
        categoryId: (int) $category->id,
        defaultAssessmentMethod: AssessmentMethod::DirectObservation,
    ));
    $course = app(TrainingCatalogStore::class)->defineCourse($companyId, new TrainingCourseDraft(
        code: 'forklift.induction',
        title: "$label Forklift Induction",
        deliveryMode: DeliveryMode::InternalClassroom,
        skillIds: [(int) $skill->id],
        internalTrainerEmployeeEntityId: $employees[1],
    ));
    $event = app(TrainingEventStore::class)->schedule($companyId, new TrainingEventDraft(
        courseId: (int) $course->id,
        startsAt: new DateTimeImmutable('2026-10-01T09:00:00+00:00'),
        endsAt: new DateTimeImmutable('2026-10-01T17:00:00+00:00'),
        capacity: 20,
        organizerEmployeeEntityId: $employees[0],
        targetDepartmentEntityId: (int) $department->getKey(),
        venue: "$label venue",
    ));

    return [
        'company' => $companyId,
        'employees' => $employees,
        'department' => (int) $department->getKey(),
        'skill' => (int) $skill->id,
        'course' => $course,
        'event' => $event,
    ];
}

/** @return array{tenant: int, a: array<string, mixed>, b: array<string, mixed>, hr: User} */
function axisFixture(): array
{
    [$tenant, $platformCompany] = createTenantWithCompany(['name' => 'Axis Tenant'], ['name' => 'Alpha Company', 'status' => 'active']);
    $tenantId = (int) $tenant->id;
    app(TenantContext::class)->set($tenantId);

    $a = axisCompany($tenantId, $platformCompany, 'Alpha');
    $b = axisCompany($tenantId, null, 'Beta');

    setupAuthzRoles();
    $hr = User::factory()->create(['company_id' => $a['company']]);
    PrincipalRole::query()->create([
        'company_id' => $a['company'],
        'principal_type' => PrincipalType::USER->value,
        'principal_id' => $hr->id,
        'role_id' => Role::query()->whereNull('company_id')->where('code', 'people_hr')->valueOrFail('id'),
    ]);

    return ['tenant' => $tenantId, 'a' => $a, 'b' => $b, 'hr' => $hr];
}

function axisDraft(array $company, array $overrides = []): TrainingEventDraft
{
    return new TrainingEventDraft(...array_merge([
        'courseId' => (int) $company['course']->id,
        'startsAt' => new DateTimeImmutable('2026-11-01T09:00:00+00:00'),
        'endsAt' => new DateTimeImmutable('2026-11-01T17:00:00+00:00'),
        'capacity' => 10,
        'organizerEmployeeEntityId' => $company['employees'][0],
        'targetDepartmentEntityId' => $company['department'],
    ], $overrides));
}

function trainingGuardMethodSource(string $class, string $method): string
{
    $reflection = new ReflectionMethod($class, $method);
    $lines = file($reflection->getFileName(), FILE_IGNORE_NEW_LINES);

    return implode("\n", array_slice(
        $lines,
        $reflection->getStartLine() - 1,
        $reflection->getEndLine() - $reflection->getStartLine() + 1,
    ));
}

test('mapped skills keeps the course tenant and company scope explicit', function (): void {
    $source = trainingGuardMethodSource(TrainingCourse::class, 'mappedSkills');

    expect($source)->toContain(
        '->forCompany((int) $this->tenant_id, (int) $this->company_entity_id)',
    );
});

test('event history keeps an explicit tenant and company scope before filtering visible ids', function (): void {
    $source = trainingGuardMethodSource(EventIndex::class, 'render');

    expect($source)->toMatch(
        '/TrainingEventAuditEvent::query\(\)\s*->forCompany\(app\(TenantContext::class\)->requireTenantId\(\), \$company\)/',
    );
});

test('a course code is unique per company, so sibling companies may share one', function (): void {
    ['tenant' => $tenantId, 'a' => $a, 'b' => $b] = axisFixture();

    // Both fixtures defined forklift.induction; each company owns its own row.
    expect(TrainingCourse::query()->forCompany($tenantId, $a['company'])->where('code', 'forklift.induction')->count())->toBe(1)
        ->and(TrainingCourse::query()->forCompany($tenantId, $b['company'])->where('code', 'forklift.induction')->count())->toBe(1)
        ->and((int) $a['course']->id)->not->toBe((int) $b['course']->id);
});

test('an event refuses an organizer, an internal trainer, or a target department from a sibling company', function (): void {
    ['a' => $a, 'b' => $b] = axisFixture();
    $store = app(TrainingEventStore::class);

    expect(fn () => $store->schedule($a['company'], axisDraft($a, ['organizerEmployeeEntityId' => $b['employees'][0]])))
        ->toThrow(InvalidTrainingEventException::class, 'Choose an active organizer from this company.')
        ->and(fn () => $store->schedule($a['company'], axisDraft($a, ['internalTrainerEmployeeEntityId' => $b['employees'][1]])))
        ->toThrow(InvalidTrainingEventException::class, 'Choose an active internal trainer from this company.')
        ->and(fn () => $store->schedule($a['company'], axisDraft($a, ['targetDepartmentEntityId' => $b['department']])))
        ->toThrow(InvalidTrainingEventException::class, 'Choose an active target department from this company.');

    expect(TrainingEvent::query()->forCompany(app(TenantContext::class)->requireTenantId(), $a['company'])->count())->toBe(1);
});

test('the register and the HR audience are scoped to one company, and attribution bounds who may manage it', function (): void {
    ['a' => $a, 'b' => $b, 'hr' => $hr] = axisFixture();
    $audience = app(TrainingAudience::class);

    expect(app(TrainingEventStore::class)->registerQuery($a['company'])->pluck('id')->all())
        ->toBe([(int) $a['event']->id]);

    expect($audience->visibleEvents($hr, $a['company'])->pluck('id')->all())
        ->toBe([(int) $a['event']->id]);

    expect($audience->canManage($hr, $a['company']))->toBeTrue()
        ->and($audience->canManage($hr, $b['company']))->toBeFalse();

    expect(fn () => $audience->authorizeManage($hr, $b['company']))->toThrow(AuthorizationDeniedException::class)
        ->and(fn () => $audience->visibleEvents($hr, $b['company']))->toThrow(AuthorizationDeniedException::class);
});

test('the catalog page lists only the acting company and refuses to open or toggle a sibling course', function (): void {
    ['a' => $a, 'b' => $b, 'hr' => $hr] = axisFixture();

    Livewire::actingAs($hr)->test(CatalogIndex::class)
        ->assertSee('Alpha Forklift Induction')
        ->assertDontSee('Beta Forklift Induction');

    Livewire::actingAs($hr)->test(CatalogIndex::class)
        ->call('editCourse', (int) $b['course']->id)
        ->assertStatus(404);

    Livewire::actingAs($hr)->test(CatalogIndex::class)
        ->call('toggleCourseActive', (int) $b['course']->id)
        ->assertStatus(404);

    expect($b['course']->refresh()->active)->toBeTrue();
});
