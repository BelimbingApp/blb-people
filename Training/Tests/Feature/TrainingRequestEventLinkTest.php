<?php

use App\Base\Authz\Enums\PrincipalType;
use App\Base\Authz\Exceptions\AuthorizationDeniedException;
use App\Base\Authz\Models\PrincipalRole;
use App\Base\Authz\Models\Role;
use App\Base\Tenancy\Contracts\TenantContext;
use App\Core\Company\Models\Company;
use App\Core\Employee\Models\Employee;
use App\Core\User\Models\User;
use App\Domains\People\Provider\Data\WorkforceSubject;
use App\Domains\People\Provider\Enums\WorkforceResourceType;
use App\Domains\People\Settings\Models\EmployeeWorkProfile;
use App\Domains\People\Settings\Models\PeopleReferenceEntry;
use App\Domains\People\Skills\Data\SkillDraft;
use App\Domains\People\Skills\Enums\AssessmentMethod;
use App\Domains\People\Skills\Services\SkillCatalogStore;
use App\Domains\People\Training\Data\TrainingCourseDraft;
use App\Domains\People\Training\Data\TrainingEventDraft;
use App\Domains\People\Training\Data\TrainingRequestDraft;
use App\Domains\People\Training\Enums\DeliveryMode;
use App\Domains\People\Training\Enums\TrainingEventStatus;
use App\Domains\People\Training\Enums\TrainingNeedSource;
use App\Domains\People\Training\Enums\TrainingPriority;
use App\Domains\People\Training\Enums\TrainingRequestStatus;
use App\Domains\People\Training\Exceptions\InvalidTrainingRequestException;
use App\Domains\People\Training\Livewire\HrGovernance\Index as HrGovernanceIndex;
use App\Domains\People\Training\Livewire\Requests\Register;
use App\Domains\People\Training\Models\TrainingEvent;
use App\Domains\People\Training\Models\TrainingRequest;
use App\Domains\People\Training\Models\TrainingRequestDecision;
use App\Domains\People\Training\Services\TrainingCatalogStore;
use App\Domains\People\Training\Services\TrainingEventStore;
use App\Domains\People\Training\Services\TrainingRequestStore;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

/**
 * Linking an approved training request to a scheduled event (0010-d):
 * the store, the two database guards, the HR queue's approved-not-linked
 * section and the register's filter and export. Self-contained: helpers
 * are prefixed reqLink.
 */
afterEach(function (): void {
    app(TenantContext::class)->clear();
});

beforeEach(function (): void {
    $this->withoutVite();
});

function reqLinkUser(Company $company, string $roleCode, string $name): User
{
    $user = User::factory()->create(['company_id' => $company->id, 'name' => $name]);
    PrincipalRole::query()->create([
        'company_id' => $company->id, 'principal_type' => PrincipalType::USER->value, 'principal_id' => $user->id,
        'role_id' => Role::query()->whereNull('company_id')->where('code', $roleCode)->sole()->id,
    ]);

    return $user;
}

/** One company side: HR user, a unit, a requestor employee, a course, and a scheduled event. */
function reqLinkSide(int $tenantId, Company $company, string $label): array
{
    $hr = reqLinkUser($company, 'people_hr', $label.' HR');
    $unit = PeopleReferenceEntry::query()->create([
        'company_id' => $company->id, 'type' => PeopleReferenceEntry::TYPE_ORGANIZATION_UNIT,
        'code' => 'OPS-'.$label, 'name' => 'Operations '.$label, 'status' => PeopleReferenceEntry::STATUS_ACTIVE,
    ]);
    $employee = Employee::factory()->create(['company_id' => $company->id, 'full_name' => $label.' Requestor', 'short_name' => null, 'supervisor_id' => null, 'status' => 'active', 'employee_type' => 'full_time']);
    EmployeeWorkProfile::query()->create(['employee_id' => $employee->id, 'organization_unit_id' => $unit->id]);
    $category = app(SkillCatalogStore::class)->defineCategory((int) $company->id, 'safety', 'Safety');
    $skill = app(SkillCatalogStore::class)->defineSkill((int) $company->id, new SkillDraft(
        code: 'isolation.energy', name: 'Energy isolation', definition: 'Isolate.', categoryId: (int) $category->id, defaultAssessmentMethod: AssessmentMethod::DirectObservation,
    ));
    $course = app(TrainingCatalogStore::class)->defineCourse((int) $company->id, new TrainingCourseDraft(
        code: 'isolation.induction', title: 'Isolation induction '.$label, deliveryMode: DeliveryMode::InternalClassroom, skillIds: [(int) $skill->id], internalTrainerEmployeeEntityId: (int) $employee->id,
    ));
    $event = app(TrainingEventStore::class)->schedule((int) $company->id, new TrainingEventDraft(
        courseId: (int) $course->id, startsAt: now()->addDays(5), endsAt: now()->addDays(6), capacity: 10, organizerEmployeeEntityId: (int) $employee->id, targetDepartmentEntityId: (int) $unit->id,
    ));

    return compact('hr', 'unit', 'employee', 'course', 'event') + ['company' => $company, 'tenantId' => $tenantId];
}

/** A request moved straight to $status with the decision rows the workflow would leave. */
function reqLinkRequest(array $s, string $need, TrainingRequestStatus $status = TrainingRequestStatus::Approved): TrainingRequest
{
    $store = app(TrainingRequestStore::class);
    $request = $store->create($s['hr'], (int) $s['company']->id, new TrainingRequestDraft(
        requestor: new WorkforceSubject($s['tenantId'], (int) $s['company']->id, WorkforceResourceType::Employee, (string) $s['employee']->id),
        department: new WorkforceSubject($s['tenantId'], (int) $s['company']->id, WorkforceResourceType::OrganizationUnit, (string) $s['unit']->id),
        needSource: TrainingNeedSource::LegalCertification, need: $need, learningObjective: 'Objective.', expectedResult: 'Result.', priority: TrainingPriority::Medium,
    ));
    $request = $store->submit($s['hr'], (int) $s['company']->id, (int) $request->id);
    if ($status !== TrainingRequestStatus::PendingHod) {
        $request->update(['status' => $status]);
    }

    return $request->fresh();
}

/** @return array{tenantId: int, alpha: array, beta: array} */
function reqLinkFixture(): array
{
    $tenant = createTenant(['name' => 'Request Link Tenant']);
    $tenantId = (int) $tenant->id;
    app(TenantContext::class)->set($tenantId);
    setupAuthzRoles();
    $alpha = Company::factory()->create(['tenant_id' => $tenantId, 'name' => 'Alpha Link', 'status' => 'active']);
    $beta = Company::factory()->create(['tenant_id' => $tenantId, 'name' => 'Beta Link', 'status' => 'active']);

    return ['tenantId' => $tenantId, 'alpha' => reqLinkSide($tenantId, $alpha, 'Alpha'), 'beta' => reqLinkSide($tenantId, $beta, 'Beta')];
}

function reqLinkDecisions(array $s, TrainingRequest $request): array
{
    return TrainingRequestDecision::query()->forCompany($s['tenantId'], (int) $s['company']->id)->where('training_request_id', $request->id)->orderBy('id')->pluck('decision')->all();
}

test('linking a scheduled event to an approved request stores the link and appends one decision row, leaving earlier rows untouched', function (): void {
    $f = reqLinkFixture();
    $a = $f['alpha'];
    $request = reqLinkRequest($a, 'Approved need');
    $before = reqLinkDecisions($a, $request);

    $linked = app(TrainingRequestStore::class)->linkEvent($a['hr'], (int) $a['company']->id, (int) $request->id, (int) $a['event']->id);
    expect($linked->training_event_id)->toBe((int) $a['event']->id)
        ->and($linked->linked_by_user_id)->toBe((int) $a['hr']->id)
        ->and($linked->linked_at)->not->toBeNull()
        ->and($linked->status)->toBe(TrainingRequestStatus::Approved);
    $after = reqLinkDecisions($a, $request);
    expect(count($after))->toBe(count($before) + 1)
        ->and(array_slice($after, 0, count($before)))->toBe($before)
        ->and(end($after))->toBe('linked')
        ->and(TrainingRequestDecision::query()->forCompany($a['tenantId'], (int) $a['company']->id)->where('training_request_id', $request->id)->latest('id')->value('notes'))->toContain('Isolation induction Alpha');

    $unlinked = app(TrainingRequestStore::class)->unlinkEvent($a['hr'], (int) $a['company']->id, (int) $request->id, 'Event cancelled by trainer');
    expect($unlinked->training_event_id)->toBeNull()
        ->and(reqLinkDecisions($a, $request))->toBe([...$after, 'unlinked']);
    expect(fn () => app(TrainingRequestStore::class)->unlinkEvent($a['hr'], (int) $a['company']->id, (int) $request->id))
        ->toThrow(InvalidTrainingRequestException::class, 'not linked');
});

test('a request that is not approved cannot be linked by the store, and a raw update is refused by the database guard', function (TrainingRequestStatus $status): void {
    $f = reqLinkFixture();
    $a = $f['alpha'];
    $request = reqLinkRequest($a, 'Not approved '.$status->value, $status);

    expect(fn () => app(TrainingRequestStore::class)->linkEvent($a['hr'], (int) $a['company']->id, (int) $request->id, (int) $a['event']->id))
        ->toThrow(InvalidTrainingRequestException::class, 'Only an approved training request');
    // In its own transaction (a savepoint under the test transaction): on
    // PostgreSQL a refused statement poisons the transaction it ran in.
    expect(fn () => DB::transaction(fn () => DB::table('people_training_requests')->where('id', $request->id)->update(['training_event_id' => $a['event']->id])))
        ->toThrow(QueryException::class, 'only an approved training request');
    expect($request->fresh()->training_event_id)->toBeNull();
})->with([TrainingRequestStatus::PendingApproval, TrainingRequestStatus::Rejected, TrainingRequestStatus::Cancelled]);

test('a completed or cancelled event, and a sibling company event, cannot satisfy a request', function (): void {
    $f = reqLinkFixture();
    $a = $f['alpha'];
    $request = reqLinkRequest($a, 'Approved need');
    $store = app(TrainingRequestStore::class);

    foreach ([TrainingEventStatus::Completed, TrainingEventStatus::Cancelled] as $status) {
        TrainingEvent::query()->forCompany($a['tenantId'], (int) $a['company']->id)->whereKey($a['event']->id)->update(['status' => $status->value]);
        expect(fn () => $store->linkEvent($a['hr'], (int) $a['company']->id, (int) $request->id, (int) $a['event']->id))
            ->toThrow(InvalidTrainingRequestException::class, 'scheduled or in-progress');
    }
    // The sibling company's event exists by id in the same tenant and is scheduled.
    expect(fn () => $store->linkEvent($a['hr'], (int) $a['company']->id, (int) $request->id, (int) $f['beta']['event']->id))
        ->toThrow(InvalidTrainingRequestException::class, 'not found in this company');
    expect(fn () => DB::transaction(fn () => DB::table('people_training_requests')->where('id', $request->id)->update(['training_event_id' => $f['beta']['event']->id])))
        ->toThrow(QueryException::class);
    expect($request->fresh()->training_event_id)->toBeNull();
});

test('an employee holding submit only cannot link, and HR of another tenant cannot reach the request', function (): void {
    $f = reqLinkFixture();
    $a = $f['alpha'];
    $request = reqLinkRequest($a, 'Approved need');
    $employee = reqLinkUser($a['company'], 'people_employee', 'Alpha Employee');
    expect(fn () => app(TrainingRequestStore::class)->linkEvent($employee, (int) $a['company']->id, (int) $request->id, (int) $a['event']->id))
        ->toThrow(AuthorizationDeniedException::class);

    $otherTenant = createTenant(['name' => 'Other Link Tenant']);
    $otherCompany = Company::factory()->create(['tenant_id' => $otherTenant->id, 'name' => 'Other Link Co', 'status' => 'active']);
    $otherHr = reqLinkUser($otherCompany, 'people_hr', 'Other HR');
    app(TenantContext::class)->set((int) $otherTenant->id);
    expect(fn () => app(TrainingRequestStore::class)->linkEvent($otherHr, (int) $a['company']->id, (int) $request->id, (int) $a['event']->id))
        ->toThrow(InvalidTrainingRequestException::class, 'unavailable in the current company scope');
    app(TenantContext::class)->set($f['tenantId']);
    expect($request->fresh()->training_event_id)->toBeNull();
});

test('the HR queue lists exactly the approved-unlinked requests of the acting company, links one through the store and it disappears', function (): void {
    $f = reqLinkFixture();
    $a = $f['alpha'];
    $unlinked = reqLinkRequest($a, 'Alpha approved unlinked');
    $pending = reqLinkRequest($a, 'Alpha pending', TrainingRequestStatus::PendingHr);
    $linked = reqLinkRequest($a, 'Alpha approved linked');
    app(TrainingRequestStore::class)->linkEvent($a['hr'], (int) $a['company']->id, (int) $linked->id, (int) $a['event']->id);
    $betaUnlinked = reqLinkRequest($f['beta'], 'Beta approved unlinked');

    $page = Livewire::actingAs($a['hr'])->test(HrGovernanceIndex::class)->assertOk();
    expect($page->viewData('approvedUnlinked')->pluck('id')->all())->toBe([$unlinked->id])
        ->and($page->viewData('approvedLinked')->pluck('id')->all())->toBe([$linked->id])
        ->and(array_keys($page->viewData('linkableEvents')))->toBe([(int) $a['event']->id]);
    $page->assertSee('Approved training requests awaiting an event')->assertSee('Alpha approved unlinked')->assertDontSee('Beta approved unlinked');

    $page->call('linkEvent', $unlinked->id)->assertHasErrors('link.'.$unlinked->id);
    expect($unlinked->fresh()->training_event_id)->toBeNull();

    $page->set('linkEventId.'.$unlinked->id, (string) $a['event']->id)->call('linkEvent', $unlinked->id)->assertHasNoErrors();
    expect($unlinked->fresh()->training_event_id)->toBe((int) $a['event']->id)
        ->and($page->viewData('approvedUnlinked'))->toHaveCount(0)
        ->and($page->viewData('approvedLinked')->pluck('id')->sort()->values()->all())->toBe(collect([$unlinked->id, $linked->id])->sort()->values()->all());

    $page->set('requestNotes.'.$linked->id, 'Trainer unavailable')->call('unlinkEvent', $linked->id)->assertHasNoErrors();
    $trail = reqLinkDecisions($a, $linked);
    expect($linked->fresh()->training_event_id)->toBeNull()
        ->and(end($trail))->toBe('unlinked');

    // A sibling company's approved request cannot be linked by id from this company.
    $page->set('linkEventId.'.$betaUnlinked->id, (string) $a['event']->id)->call('linkEvent', $betaUnlinked->id);
    expect($betaUnlinked->fresh()->training_event_id)->toBeNull();
});

test('the register filter approved_unlinked matches approvedUnlinkedQuery and the export carries the linked event columns', function (): void {
    $f = reqLinkFixture();
    $a = $f['alpha'];
    $unlinked = reqLinkRequest($a, 'Alpha approved unlinked');
    $linked = reqLinkRequest($a, 'Alpha approved linked');
    reqLinkRequest($a, 'Alpha pending', TrainingRequestStatus::PendingHr);
    app(TrainingRequestStore::class)->linkEvent($a['hr'], (int) $a['company']->id, (int) $linked->id, (int) $a['event']->id);

    $page = Livewire::actingAs($a['hr'])->test(Register::class)->set('status', Register::FILTER_APPROVED_UNLINKED);
    $expected = app(TrainingRequestStore::class)->approvedUnlinkedQuery($f['tenantId'], (int) $a['company']->id)->pluck('id')->all();
    expect($page->viewData('rows')->pluck('id')->all())->toBe($expected)->and($expected)->toBe([$unlinked->id]);
    $page->assertSee('Training requests register');

    $all = Livewire::actingAs($a['hr'])->test(Register::class);
    $rows = $all->viewData('rows')->keyBy('id');
    expect($rows[$linked->id]['linked_event_id'])->toBe((string) $a['event']->id)
        ->and($rows[$linked->id]['linked_event_title'])->toBe('Isolation induction Alpha')
        ->and($rows[$unlinked->id]['linked_event_id'])->toBe('')
        ->and($rows[$unlinked->id]['linked_event_title'])->toBe('');

    $all->call('export');
    $csv = base64_decode($all->effects['download']['content']);
    $lines = array_values(array_filter(explode("\n", trim($csv))));
    expect($lines[0])->toEndWith(',decided_at,linked_event_id,linked_event_title');
    $byId = [];
    foreach (array_slice($lines, 1) as $line) {
        $cells = str_getcsv($line);
        $byId[(int) $cells[0]] = $cells;
    }
    expect(array_slice($byId[$linked->id], -2))->toBe([(string) $a['event']->id, 'Isolation induction Alpha'])
        ->and(array_slice($byId[$unlinked->id], -2))->toBe(['', '']);
});
