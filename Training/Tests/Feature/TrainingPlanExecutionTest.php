<?php

use App\Base\Authz\Enums\PrincipalType;
use App\Base\Authz\Models\PrincipalRole;
use App\Base\Authz\Models\Role;
use App\Base\Tenancy\Contracts\TenantContext;
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
use App\Domains\People\Training\Data\TrainingCourseDraft;
use App\Domains\People\Training\Data\TrainingEventDraft;
use App\Domains\People\Training\Data\TrainingPlanDraft;
use App\Domains\People\Training\Data\TrainingPlanItemDraft;
use App\Domains\People\Training\Enums\DeliveryMode;
use App\Domains\People\Training\Enums\TrainingDeliveryApproach;
use App\Domains\People\Training\Enums\TrainingEventStatus;
use App\Domains\People\Training\Exceptions\InvalidTrainingPlanExecutionException;
use App\Domains\People\Training\Models\TrainingEvent;
use App\Domains\People\Training\Models\TrainingPlan;
use App\Domains\People\Training\Models\TrainingPlanItem;
use App\Domains\People\Training\Services\TrainingCatalogStore;
use App\Domains\People\Training\Services\TrainingEventStore;
use App\Domains\People\Training\Services\TrainingPlanExecution;
use App\Domains\People\Training\Services\TrainingPlanStore;
use Illuminate\Database\QueryException;
use Illuminate\Support\Str;

afterEach(function (): void {
    app(TenantContext::class)->clear();
});

/**
 * Self-contained: Pest does not load helpers from sibling test files when a
 * single file is run, so this fixture repeats the plan and event recipes
 * rather than borrowing them.
 *
 * @return array<string, mixed>
 */
function planExecFixture(): array
{
    [$tenant, $company] = createTenantWithCompany();
    app(TenantContext::class)->set((int) $tenant->id);

    $entry = PeopleReferenceEntry::query()->create([
        'company_id' => $company->id, 'type' => PeopleReferenceEntry::TYPE_ORGANIZATION_UNIT,
        'code' => 'OPS-EXEC', 'name' => 'Operations execution', 'status' => PeopleReferenceEntry::STATUS_ACTIVE,
    ]);
    $type = DepartmentType::query()->create([
        'code' => 'ops-exec', 'name' => 'Operations execution', 'category' => 'operational', 'is_active' => true,
    ]);
    $department = Department::query()->create([
        'company_id' => $company->id, 'department_type_id' => $type->id, 'status' => 'active',
    ]);
    $head = Employee::factory()->create([
        'company_id' => $company->id, 'department_id' => $department->id,
        'full_name' => 'Execution HOD', 'status' => 'active', 'employee_type' => 'full_time',
    ]);
    $department->update(['head_id' => $head->id]);
    EmployeeWorkProfile::query()->create(['employee_id' => $head->id, 'organization_unit_id' => $entry->id]);

    $hr = User::factory()->create(['company_id' => $company->id]);
    $hod = User::factory()->create(['company_id' => $company->id, 'employee_id' => $head->id]);
    EmployeePortalAccess::query()->create([
        'employee_id' => $head->id, 'user_id' => $hod->id,
        'display_name' => 'Execution HOD', 'status' => EmployeePortalAccess::STATUS_ACTIVE,
    ]);
    setupAuthzRoles();
    foreach ([[$hr, 'people_hr'], [$hod, 'people_hod']] as [$actor, $code]) {
        $role = Role::query()->whereNull('company_id')->where('code', $code)->sole();
        PrincipalRole::query()->create([
            'company_id' => $company->id, 'principal_type' => PrincipalType::USER->value,
            'principal_id' => $actor->id, 'role_id' => $role->id,
        ]);
    }
    app(SkillAudienceAssignmentStore::class)->confirmActor(
        $hr, $hod, (int) $company->id, (int) $head->id, 'review:plan-execution-hod',
    );

    $category = app(SkillCatalogStore::class)->defineCategory((int) $company->id, 'safety', 'Safety');
    $skill = app(SkillCatalogStore::class)->defineSkill((int) $company->id, new SkillDraft(
        code: 'lockout.tagout',
        name: 'Lockout tagout',
        definition: 'Isolate energy before maintenance.',
        categoryId: (int) $category->id,
        defaultAssessmentMethod: AssessmentMethod::DirectObservation,
    ));
    $course = app(TrainingCatalogStore::class)->defineCourse((int) $company->id, new TrainingCourseDraft(
        code: 'lockout.induction',
        title: 'Lockout induction',
        deliveryMode: DeliveryMode::InternalClassroom,
        skillIds: [(int) $skill->id],
        internalTrainerEmployeeEntityId: (int) $head->id,
    ));

    return compact('tenant', 'company', 'entry', 'head', 'hr', 'hod', 'course');
}

function planExecItemDraft(array $f, string $needReference): TrainingPlanItemDraft
{
    return new TrainingPlanItemDraft(
        needTenantId: (int) $f['tenant']->id,
        needCompanyEntityId: (int) $f['company']->id,
        needReference: $needReference,
        expectedResult: 'Technicians isolate energy to the governed standard.',
        targetCohort: 'Maintenance technicians',
        deliveryApproach: TrainingDeliveryApproach::Mixed,
        responsibleOwnerReference: 'employee:'.$f['head']->id,
        intendedTiming: '2027-Q1',
        evaluationApproach: 'Observed isolation task.',
    );
}

/** @param list<string> $needReferences */
function planExecApprovedPlan(array $f, array $needReferences = ['need:LOTO-1']): TrainingPlan
{
    $store = app(TrainingPlanStore::class);
    $plan = $store->createDraft($f['hod'], (int) $f['company']->id, new TrainingPlanDraft(
        departmentEntityId: (int) $f['entry']->id,
        periodStart: new DateTimeImmutable('2027-01-01'),
        periodEnd: new DateTimeImmutable('2027-12-31'),
        objectives: 'Close the governed isolation gaps.',
        financialTrackingEnabled: false,
        items: array_map(fn (string $r): TrainingPlanItemDraft => planExecItemDraft($f, $r), $needReferences),
    ));
    $store->submit($f['hod'], (int) $f['company']->id, (int) $plan->id);

    return $store->approve($f['hr'], (int) $f['company']->id, (int) $plan->id);
}

function planExecEventDraft(array $f): TrainingEventDraft
{
    return new TrainingEventDraft(
        courseId: (int) $f['course']->id,
        startsAt: new DateTimeImmutable('2027-03-01T09:00:00+00:00'),
        endsAt: new DateTimeImmutable('2027-03-01T17:00:00+00:00'),
        capacity: 12,
        organizerEmployeeEntityId: (int) $f['head']->id,
        targetDepartmentEntityId: (int) $f['entry']->id,
    );
}

test('an approved plan item becomes a training event exactly once, carrying its plan provenance', function (): void {
    $f = planExecFixture();
    $plan = planExecApprovedPlan($f);
    $item = $plan->items()->sole();
    $execution = app(TrainingPlanExecution::class);

    $first = $execution->execute((int) $f['company']->id, (int) $item->id, planExecEventDraft($f));
    $second = $execution->execute((int) $f['company']->id, (int) $item->id, planExecEventDraft($f));

    expect($second->id)->toBe($first->id)
        ->and(TrainingEvent::query()->forCompany((int) $f['tenant']->id, (int) $f['company']->id)->count())->toBe(1)
        ->and((int) $first->plan_id)->toBe((int) $plan->id)
        ->and((int) $first->plan_version)->toBe(1)
        ->and((int) $first->plan_item_id)->toBe((int) $item->id)
        ->and($first->plan_item_key)->toBe($item->item_key);
});

test('an amendment that keeps an item does not schedule the event a second time', function (): void {
    $f = planExecFixture();
    $company = (int) $f['company']->id;
    $first = planExecApprovedPlan($f);
    $execution = app(TrainingPlanExecution::class);
    $event = $execution->execute($company, (int) $first->items()->sole()->id, planExecEventDraft($f));

    $store = app(TrainingPlanStore::class);
    $second = $store->amend($f['hod'], $company, (int) $first->id, 'Restate the same need for the new period.');
    $store->submit($f['hod'], $company, (int) $second->id);
    $amended = $store->approve($f['hr'], $company, (int) $second->id);
    $copied = $amended->items()->sole();

    // The copy is a different row with the same stable item key: that is what
    // makes the second execution a no-op rather than a duplicate event.
    expect((int) $copied->id)->not->toBe((int) $first->items()->sole()->id)
        ->and($copied->item_key)->toBe($first->items()->sole()->item_key);

    $again = $execution->execute($company, (int) $copied->id, planExecEventDraft($f));

    expect($again->id)->toBe($event->id)
        ->and(TrainingEvent::query()->forCompany((int) $f['tenant']->id, $company)->count())->toBe(1);
});

test('an item dropped by an approved amendment cancels its unstarted event and leaves the kept one alone', function (): void {
    $f = planExecFixture();
    $company = (int) $f['company']->id;
    $first = planExecApprovedPlan($f, ['need:LOTO-1', 'need:LOTO-2']);
    $execution = app(TrainingPlanExecution::class);
    $items = $first->items()->orderBy('need_reference')->get();
    $kept = $execution->execute($company, (int) $items[0]->id, planExecEventDraft($f));
    $dropped = $execution->execute($company, (int) $items[1]->id, planExecEventDraft($f));

    $store = app(TrainingPlanStore::class);
    $second = $store->amend($f['hod'], $company, (int) $first->id, 'Drop the second need.');
    TrainingPlanItem::query()->forCompany((int) $f['tenant']->id, $company)
        ->where('training_plan_id', $second->id)->where('need_reference', 'need:LOTO-2')->sole()->delete();
    $store->submit($f['hod'], $company, (int) $second->id);
    $store->approve($f['hr'], $company, (int) $second->id);

    expect($dropped->refresh()->status)->toBe(TrainingEventStatus::Cancelled)
        ->and($dropped->cancellation_reason)->toContain('amend')
        ->and($kept->refresh()->status)->toBe(TrainingEventStatus::Scheduled);
});

test('an amendment does not cancel an event that has already started', function (): void {
    $f = planExecFixture();
    $company = (int) $f['company']->id;
    $first = planExecApprovedPlan($f, ['need:LOTO-1', 'need:LOTO-2']);
    $execution = app(TrainingPlanExecution::class);
    $items = $first->items()->orderBy('need_reference')->get();
    $execution->execute($company, (int) $items[0]->id, planExecEventDraft($f));
    $started = $execution->execute($company, (int) $items[1]->id, planExecEventDraft($f));
    $this->travelTo(new DateTimeImmutable('2027-03-01T10:00:00+00:00'));
    app(TrainingEventStore::class)->start($company, (int) $started->id);

    $store = app(TrainingPlanStore::class);
    $second = $store->amend($f['hod'], $company, (int) $first->id, 'Drop the second need.');
    TrainingPlanItem::query()->forCompany((int) $f['tenant']->id, $company)
        ->where('training_plan_id', $second->id)->where('need_reference', 'need:LOTO-2')->sole()->delete();
    $store->submit($f['hod'], $company, (int) $second->id);
    $store->approve($f['hr'], $company, (int) $second->id);

    expect($started->refresh()->status)->toBe(TrainingEventStatus::InProgress);
    $this->travelBack();
});

test('a plan item whose revision is not approved cannot be executed', function (): void {
    $f = planExecFixture();
    $company = (int) $f['company']->id;
    $draft = app(TrainingPlanStore::class)->createDraft($f['hod'], $company, new TrainingPlanDraft(
        departmentEntityId: (int) $f['entry']->id,
        periodStart: new DateTimeImmutable('2027-01-01'),
        periodEnd: new DateTimeImmutable('2027-12-31'),
        objectives: 'Close the governed isolation gaps.',
        financialTrackingEnabled: false,
        items: [planExecItemDraft($f, 'need:LOTO-1')],
    ));

    expect(fn () => app(TrainingPlanExecution::class)
        ->execute($company, (int) $draft->items()->sole()->id, planExecEventDraft($f)))
        ->toThrow(InvalidTrainingPlanExecutionException::class, 'approved');
    expect(TrainingEvent::query()->forCompany((int) $f['tenant']->id, $company)->count())->toBe(0);
});

test('amending one plan lineage leaves another lineage\'s events alone', function (): void {
    $f = planExecFixture();
    $company = (int) $f['company']->id;
    $store = app(TrainingPlanStore::class);
    $execution = app(TrainingPlanExecution::class);

    $mine = planExecApprovedPlan($f, ['need:LOTO-1']);
    $theirs = planExecApprovedPlan($f, ['need:LOTO-9']);
    expect($theirs->plan_key)->not->toBe($mine->plan_key);

    $execution->execute($company, (int) $mine->items()->sole()->id, planExecEventDraft($f));
    $other = $execution->execute($company, (int) $theirs->items()->sole()->id, planExecEventDraft($f));

    $second = $store->amend($f['hod'], $company, (int) $mine->id, 'Drop the only need.');
    TrainingPlanItem::query()->forCompany((int) $f['tenant']->id, $company)
        ->where('training_plan_id', $second->id)->sole()->delete();
    $store->submit($f['hod'], $company, (int) $second->id);
    $store->approve($f['hr'], $company, (int) $second->id);

    // The other plan's item is not in *this* plan's current items either, so
    // without the lineage scope the reconciliation would cancel it too.
    expect($other->refresh()->status)->toBe(TrainingEventStatus::Scheduled);
});

test('the database refuses a second event for one plan item key', function (): void {
    $f = planExecFixture();
    $company = (int) $f['company']->id;
    $plan = planExecApprovedPlan($f);
    $item = $plan->items()->sole();
    $event = app(TrainingPlanExecution::class)->execute($company, (int) $item->id, planExecEventDraft($f));

    // The service short-circuits before it ever inserts a second row, so the
    // only way to show the index is load-bearing is to write around it — which
    // is what two concurrent executions would do.
    $duplicate = $event->replicate(['id']);
    $duplicate->event_key = (string) Str::uuid();

    expect(fn () => $duplicate->save())->toThrow(QueryException::class);
});
