<?php

use App\Base\Tenancy\Contracts\TenantContext;
use App\Core\Company\Models\Company;
use App\Core\Employee\Models\Employee;
use App\Domains\People\Skills\Contracts\ReferencesWorkforceEntities;
use App\Domains\People\Skills\Data\SkillDraft;
use App\Domains\People\Skills\Enums\AssessmentMethod;
use App\Domains\People\Skills\Exceptions\MissingCompanyScopeException;
use App\Domains\People\Skills\Services\SkillCatalogStore;
use App\Domains\People\Training\Data\TrainingCourseDraft;
use App\Domains\People\Training\Enums\DeliveryMode;
use App\Domains\People\Training\Events\TrainingCourseDeactivated;
use App\Domains\People\Training\Events\TrainingCourseDefined;
use App\Domains\People\Training\Events\TrainingCourseReactivated;
use App\Domains\People\Training\Exceptions\InvalidTrainingCatalogException;
use App\Domains\People\Training\Exceptions\TrainingCatalogRecordNotFoundException;
use App\Domains\People\Training\Models\TrainingCourse;
use App\Domains\People\Training\Services\TrainingCatalogStore;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

afterEach(function (): void {
    app(TenantContext::class)->clear();
});

/**
 * A workforce subject is a native People record now, not a connector entity
 * row: the seam resolves companies and employees through the provider, so the
 * stable id a caller passes is a Core key. Same fixture intent, one indirection
 * fewer.
 */
function trainingCatalogCompany(int $tenantId): int
{
    return (int) Company::factory()->create(['tenant_id' => $tenantId, 'status' => 'active'])->id;
}

function trainingCatalogEmployee(int $tenantId, ?int $companyId = null): int
{
    return (int) Employee::factory()->create([
        'company_id' => $companyId ?? trainingCatalogCompany($tenantId),
        'status' => 'active',
    ])->id;
}

/**
 * @return array{int, int, int, int} [tenantId, companyEntityId, skillId, categoryId]
 */
function trainingCatalogFixture(string $tenantName = 'Training Catalog Tenant'): array
{
    $tenant = createTenant(['name' => $tenantName]);
    app(TenantContext::class)->set((int) $tenant->id);

    $companyId = trainingCatalogCompany((int) $tenant->id);
    $category = app(SkillCatalogStore::class)->defineCategory($companyId, 'safety', 'Safety');
    $skill = app(SkillCatalogStore::class)->defineSkill($companyId, new SkillDraft(
        code: 'forklift.operation',
        name: 'Forklift Operation',
        definition: 'Operates a counterbalance forklift to the approved standard.',
        categoryId: (int) $category->id,
        defaultAssessmentMethod: AssessmentMethod::DirectObservation,
    ));

    return [(int) $tenant->id, $companyId, (int) $skill->id, (int) $category->id];
}

function trainingCourseDraft(int $skillId, array $overrides = []): TrainingCourseDraft
{
    return new TrainingCourseDraft(...array_merge([
        'code' => 'forklift.induction',
        'title' => 'Forklift Induction',
        'deliveryMode' => DeliveryMode::InternalClassroom,
        'skillIds' => [$skillId],
        'description' => 'Induction course for new forklift operators.',
    ], $overrides));
}

test('a training course carries the workbook fields, maps its skills, and fires a lifecycle event', function (): void {
    Event::fake([TrainingCourseDefined::class]);
    [, $companyEntityId, $skillId] = trainingCatalogFixture();

    $course = app(TrainingCatalogStore::class)->defineCourse($companyEntityId, trainingCourseDraft($skillId));

    expect($course->code)->toBe('forklift.induction')
        ->and($course->title)->toBe('Forklift Induction')
        ->and($course->delivery_mode)->toBe(DeliveryMode::InternalClassroom)
        ->and($course->active)->toBeTrue()
        ->and($course->skillIds())->toBe([$skillId])
        ->and($course->mappedSkills()->pluck('id')->all())->toBe([$skillId])
        ->and($course->getAuditSubject())->toBe(['name' => 'training_course', 'id' => $course->id]);

    Event::assertDispatched(TrainingCourseDefined::class, fn (TrainingCourseDefined $event): bool => $event->created && $event->code === 'forklift.induction');
});

test('course codes are stable: duplicates are refused and revision cannot rename', function (): void {
    [, $companyEntityId, $skillId] = trainingCatalogFixture();
    $store = app(TrainingCatalogStore::class);

    $course = $store->defineCourse($companyEntityId, trainingCourseDraft($skillId));

    expect(fn () => $store->defineCourse($companyEntityId, trainingCourseDraft($skillId)))
        ->toThrow(InvalidTrainingCatalogException::class, 'already exists');

    expect(fn () => $store->reviseCourse($companyEntityId, (int) $course->id, trainingCourseDraft($skillId, ['code' => 'forklift.renamed'])))
        ->toThrow(InvalidTrainingCatalogException::class, 'stable');
});

test('code stability is enforced at the model layer too, independent of the store', function (): void {
    [$tenantId, $companyEntityId, $skillId] = trainingCatalogFixture();
    $course = app(TrainingCatalogStore::class)->defineCourse($companyEntityId, trainingCourseDraft($skillId));

    // Goes around TrainingCatalogStore entirely — reviseCourse() already
    // refuses a changed code before any model write, so this is the only
    // path that reaches TrainingCourse::booted()'s own guard.
    app(TenantContext::class)->set($tenantId);
    $loaded = TrainingCourse::query()->forCompany($tenantId, $companyEntityId)->findOrFail($course->id);

    expect(fn () => $loaded->update(['code' => 'forklift.renamed']))
        ->toThrow(InvalidTrainingCatalogException::class, 'stable');
});

test('a course must map to at least one skill, and every mapped skill must belong to the same company', function (): void {
    [$tenantId, $companyEntityId, $skillId] = trainingCatalogFixture();
    $store = app(TrainingCatalogStore::class);

    // Sibling company in the *same* tenant — the company axis, not a second tenant (#92).
    $siblingCompanyId = trainingCatalogCompany($tenantId);
    $siblingCategory = app(SkillCatalogStore::class)->defineCategory($siblingCompanyId, 'safety', 'Safety');
    $siblingSkill = app(SkillCatalogStore::class)->defineSkill($siblingCompanyId, new SkillDraft(
        code: 'forklift.operation',
        name: 'Forklift Operation',
        definition: 'Sibling-company skill.',
        categoryId: (int) $siblingCategory->id,
        defaultAssessmentMethod: AssessmentMethod::DirectObservation,
    ));

    expect(fn () => $store->defineCourse($companyEntityId, trainingCourseDraft($skillId, ['skillIds' => []])))
        ->toThrow(InvalidTrainingCatalogException::class, 'at least one skill');

    expect(fn () => $store->defineCourse($companyEntityId, trainingCourseDraft($skillId, [
        'skillIds' => [$skillId, (int) $siblingSkill->id],
    ])))->toThrow(InvalidTrainingCatalogException::class, 'same company catalog');
});

test('blank titles and illegal codes fail closed (#92)', function (): void {
    [, $companyEntityId, $skillId] = trainingCatalogFixture();
    $store = app(TrainingCatalogStore::class);

    expect(fn () => $store->defineCourse($companyEntityId, trainingCourseDraft($skillId, [
        'title' => '   ',
    ])))->toThrow(InvalidTrainingCatalogException::class, 'title');

    expect(fn () => $store->defineCourse($companyEntityId, trainingCourseDraft($skillId, [
        'code' => 'Bad Code!',
    ])))->toThrow(InvalidTrainingCatalogException::class, 'lowercase');
});

test('database rejects a sibling-company skill planted on the course join table (#92)', function (): void {
    [$tenantId, $companyEntityId, $skillId] = trainingCatalogFixture();
    $store = app(TrainingCatalogStore::class);
    $course = $store->defineCourse($companyEntityId, trainingCourseDraft($skillId));

    $siblingCompanyId = trainingCatalogCompany($tenantId);
    $siblingCategory = app(SkillCatalogStore::class)->defineCategory($siblingCompanyId, 'ops', 'Ops');
    $siblingSkill = app(SkillCatalogStore::class)->defineSkill($siblingCompanyId, new SkillDraft(
        code: 'sibling.skill',
        name: 'Sibling Skill',
        definition: 'Must not attach across company ownership.',
        categoryId: (int) $siblingCategory->id,
        defaultAssessmentMethod: AssessmentMethod::DirectObservation,
    ));

    // Raw insert must fail closed at the company-owner DB guard (not only via mappedSkills).
    // Savepoint-wrapped: a trigger abort poisons the test transaction on Postgres.
    expect(fn () => DB::transaction(fn () => DB::table('people_connector_training_course_skills')->insert([
        'tenant_id' => $tenantId,
        'course_id' => $course->id,
        'skill_id' => $siblingSkill->id,
    ])))->toThrow(QueryException::class);

    expect($course->fresh()->skillIds())->toBe([$skillId])
        ->and($course->mappedSkills()->pluck('id')->all())->toBe([$skillId]);
});

test('revising a course replaces its skill mapping rather than accumulating it', function (): void {
    [, $companyEntityId, $skillId, $categoryId] = trainingCatalogFixture();
    $store = app(TrainingCatalogStore::class);
    $secondSkill = app(SkillCatalogStore::class)->defineSkill($companyEntityId, new SkillDraft(
        code: 'forklift.maintenance',
        name: 'Forklift Maintenance',
        definition: 'Performs routine forklift maintenance checks.',
        categoryId: $categoryId,
        defaultAssessmentMethod: AssessmentMethod::DirectObservation,
    ));

    $course = $store->defineCourse($companyEntityId, trainingCourseDraft($skillId));
    $revised = $store->reviseCourse($companyEntityId, (int) $course->id, trainingCourseDraft($skillId, [
        'skillIds' => [(int) $secondSkill->id],
        'title' => 'Forklift Induction (Revised)',
    ]));

    expect($revised->title)->toBe('Forklift Induction (Revised)')
        ->and($revised->skillIds())->toBe([(int) $secondSkill->id]);
});

test('deactivate and reactivate toggle the course and fire the deactivation event', function (): void {
    Event::fake([TrainingCourseDeactivated::class]);
    [, $companyEntityId, $skillId] = trainingCatalogFixture();
    $store = app(TrainingCatalogStore::class);
    $course = $store->defineCourse($companyEntityId, trainingCourseDraft($skillId));

    $deactivated = $store->deactivateCourse($companyEntityId, (int) $course->id);
    expect($deactivated->active)->toBeFalse();

    $reactivated = $store->reactivateCourse($companyEntityId, (int) $course->id);
    expect($reactivated->active)->toBeTrue();

    Event::assertDispatched(TrainingCourseDeactivated::class, fn (TrainingCourseDeactivated $event): bool => $event->code === 'forklift.induction');
});

test('reviseCourse does not change availability or skip lifecycle events (#91)', function (): void {
    [, $companyEntityId, $skillId] = trainingCatalogFixture();
    $store = app(TrainingCatalogStore::class);
    $course = $store->defineCourse($companyEntityId, trainingCourseDraft($skillId));
    $store->deactivateCourse($companyEntityId, (int) $course->id);

    Event::fake([TrainingCourseDeactivated::class, TrainingCourseReactivated::class]);

    // Ordinary draft defaults active=true — must not silently reactivate.
    $revised = $store->reviseCourse($companyEntityId, (int) $course->id, trainingCourseDraft($skillId, [
        'title' => 'Forklift Induction (Content only)',
    ]));
    expect($revised->active)->toBeFalse()
        ->and($revised->title)->toBe('Forklift Induction (Content only)');

    // Explicit active:false on revise must not deactivate via the revise path either.
    $store->reactivateCourse($companyEntityId, (int) $course->id);
    Event::fake([TrainingCourseDeactivated::class, TrainingCourseReactivated::class]);

    $stillActive = $store->reviseCourse($companyEntityId, (int) $course->id, trainingCourseDraft($skillId, [
        'active' => false,
        'title' => 'Still active after revise',
    ]));
    expect($stillActive->active)->toBeTrue()
        ->and($stillActive->title)->toBe('Still active after revise');

    Event::assertNotDispatched(TrainingCourseDeactivated::class);
    Event::assertNotDispatched(TrainingCourseReactivated::class);
});

test('a course cannot be reached, revised, or deactivated across a sibling company in the same tenant', function (): void {
    [$tenantId, $companyEntityId, $skillId] = trainingCatalogFixture();
    $siblingCompanyId = trainingCatalogCompany($tenantId);
    $store = app(TrainingCatalogStore::class);
    $course = $store->defineCourse($companyEntityId, trainingCourseDraft($skillId));

    expect(fn () => $store->reviseCourse($siblingCompanyId, (int) $course->id, trainingCourseDraft($skillId)))
        ->toThrow(TrainingCatalogRecordNotFoundException::class)
        ->and(fn () => $store->deactivateCourse($siblingCompanyId, (int) $course->id))
        ->toThrow(TrainingCatalogRecordNotFoundException::class)
        ->and(fn () => $store->reactivateCourse($siblingCompanyId, (int) $course->id))
        ->toThrow(TrainingCatalogRecordNotFoundException::class);
});

test('an internal trainer must be an active employee projection in the selected company', function (): void {
    [$tenantId, $companyEntityId, $skillId] = trainingCatalogFixture();

    expect(fn () => app(TrainingCatalogStore::class)->defineCourse($companyEntityId, trainingCourseDraft($skillId, [
        'internalTrainerEmployeeEntityId' => 999_999,
    ])))->toThrow(InvalidTrainingCatalogException::class, 'employee workforce entity');

    // The seam resolves employees per company, so a sibling company's employee
    // is unknown here rather than "known but inactive": the existence check
    // refuses it before the activity check can. The connector's projection
    // lookup was tenant-wide and reached the second message.
    $trainer = trainingCatalogEmployee($tenantId);
    expect(fn () => app(TrainingCatalogStore::class)->defineCourse($companyEntityId, trainingCourseDraft($skillId, [
        'internalTrainerEmployeeEntityId' => $trainer,
    ])))->toThrow(InvalidTrainingCatalogException::class, 'employee workforce entity');
});

test('entity references are checked by workforce type, not merely by existing in the tenant', function (): void {
    [$tenantId, $companyEntityId, $skillId] = trainingCatalogFixture();
    // Native ids are per table, so an employee key can coincide with a company
    // key; pin the employee to a key no company holds so the refusal below is
    // the type check, not a lucky collision.
    $employeeEntity = (int) Employee::factory()->create([
        'id' => 900_001,
        'company_id' => trainingCatalogCompany($tenantId),
        'status' => 'active',
    ])->id;

    // A real employee entity used as the company argument must be refused —
    // an id existing somewhere in the tenant is not the same as it being the
    // right kind of workforce entity for the field it is passed to.
    expect(fn () => app(TrainingCatalogStore::class)->defineCourse($employeeEntity, trainingCourseDraft($skillId)))
        ->toThrow(InvalidTrainingCatalogException::class, 'company workforce entity');

    // Symmetrically, a real company entity used as the trainer must be
    // refused — the trainer field names an employee, not any workforce entity.
    expect(fn () => app(TrainingCatalogStore::class)->defineCourse($companyEntityId, trainingCourseDraft($skillId, [
        'internalTrainerEmployeeEntityId' => $companyEntityId,
    ])))->toThrow(InvalidTrainingCatalogException::class, 'employee workforce entity');
});

test('a training course table row participates in company isolation like the skill tables it references', function (): void {
    expect(is_a(TrainingCourse::class, ReferencesWorkforceEntities::class, true))->toBeTrue();

    [, $companyEntityId, $skillId] = trainingCatalogFixture();
    $course = app(TrainingCatalogStore::class)->defineCourse($companyEntityId, trainingCourseDraft($skillId));

    expect(fn () => TrainingCourse::query()->where('id', $course->id)->get())
        ->toThrow(MissingCompanyScopeException::class);
});
