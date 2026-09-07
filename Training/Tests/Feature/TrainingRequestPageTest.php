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
use App\Domains\People\Provider\Data\WorkforceSubject;
use App\Domains\People\Provider\Enums\WorkforceResourceType;
use App\Domains\People\Settings\Models\EmployeePortalAccess;
use App\Domains\People\Settings\Models\EmployeeWorkProfile;
use App\Domains\People\Settings\Models\PeopleReferenceEntry;
use App\Domains\People\Skills\Services\SkillAudienceAssignmentStore;
use App\Domains\People\Training\Data\TrainingRequestDraft;
use App\Domains\People\Training\Enums\TrainingNeedSource;
use App\Domains\People\Training\Enums\TrainingPriority;
use App\Domains\People\Training\Enums\TrainingRequestStatus;
use App\Domains\People\Training\Livewire\Request\Index;
use App\Domains\People\Training\Models\TrainingRequest;
use App\Domains\People\Training\Services\TrainingRequestStore;
use Illuminate\Support\Collection;
use Livewire\Livewire;

/**
 * Training request page (0005-i): an employee drafts for themself, a HOD
 * for a department member and recommends; every write is the store's and
 * every status shown is the row's. Self-contained: helpers are prefixed
 * trainingReq.
 */
afterEach(function (): void {
    app(TenantContext::class)->clear();
});

beforeEach(function (): void {
    $this->withoutVite();
});

function trainingReqUser(Company $company, string $roleCode): User
{
    $user = User::factory()->create(['company_id' => $company->id]);
    PrincipalRole::query()->create([
        'company_id' => $company->id, 'principal_type' => PrincipalType::USER->value, 'principal_id' => $user->id,
        'role_id' => Role::query()->whereNull('company_id')->where('code', $roleCode)->sole()->id,
    ]);

    return $user;
}

/** @return array{entry: PeopleReferenceEntry, department: Department} */
function trainingReqDepartment(Company $company, string $code, string $name): array
{
    $entry = PeopleReferenceEntry::query()->create([
        'company_id' => $company->id, 'type' => PeopleReferenceEntry::TYPE_ORGANIZATION_UNIT,
        'code' => $code, 'name' => $name, 'status' => PeopleReferenceEntry::STATUS_ACTIVE,
    ]);
    $type = DepartmentType::query()->firstOrCreate(['code' => strtolower($code).'-req'], ['name' => $name.' requests', 'category' => 'operational', 'is_active' => true]);
    $department = Department::query()->create(['company_id' => $company->id, 'department_type_id' => $type->id, 'status' => 'active']);

    return ['entry' => $entry, 'department' => $department];
}

function trainingReqEmployee(Company $company, Department $department, PeopleReferenceEntry $unit, string $name): Employee
{
    $employee = Employee::factory()->create(['company_id' => $company->id, 'department_id' => $department->id, 'full_name' => $name, 'short_name' => null, 'supervisor_id' => null, 'status' => 'active', 'employee_type' => 'full_time']);
    EmployeeWorkProfile::query()->create(['employee_id' => $employee->id, 'organization_unit_id' => $unit->id]);

    return $employee;
}

function trainingReqBind(User $hr, User $user, Company $company, Employee $employee, string $reason): void
{
    $user->update(['employee_id' => $employee->id]);
    EmployeePortalAccess::query()->create(['employee_id' => $employee->id, 'user_id' => $user->id, 'display_name' => $employee->full_name, 'status' => EmployeePortalAccess::STATUS_ACTIVE]);
    app(SkillAudienceAssignmentStore::class)->confirmActor($hr, $user, (int) $company->id, (int) $employee->id, $reason);
}

/**
 * One company: Operations headed by the HOD, with a member (the employee
 * user), and Quality with its own head and member. HR only binds people.
 *
 * @return array<string, mixed>
 */
function trainingReqFixture(): array
{
    $tenant = createTenant(['name' => 'Training Request Tenant']);
    $tenantId = (int) $tenant->id;
    app(TenantContext::class)->set($tenantId);
    setupAuthzRoles();
    $company = Company::factory()->create(['tenant_id' => $tenantId, 'name' => 'Request Co', 'status' => 'active']);
    $hr = trainingReqUser($company, 'people_hr');

    $ops = trainingReqDepartment($company, 'OPS', 'Operations');
    $qa = trainingReqDepartment($company, 'QA', 'Quality');
    $head = trainingReqEmployee($company, $ops['department'], $ops['entry'], 'Ops Head');
    $ops['department']->update(['head_id' => $head->id]);
    $qaHead = trainingReqEmployee($company, $qa['department'], $qa['entry'], 'Quality Head');
    $qa['department']->update(['head_id' => $qaHead->id]);
    $member = trainingReqEmployee($company, $ops['department'], $ops['entry'], 'Ops Member');
    $qaMember = trainingReqEmployee($company, $qa['department'], $qa['entry'], 'Quality Member');
    // Reports to the Operations head but sits in Quality: the HOD may draft
    // for them, yet their request routes through a unit the HOD does not head.
    $reports = trainingReqEmployee($company, $qa['department'], $qa['entry'], 'Reports To Ops Head');
    $reports->update(['supervisor_id' => $head->id]);

    $hod = trainingReqUser($company, 'people_hod');
    trainingReqBind($hr, $hod, $company, $head, 'review:req-hod');
    $employee = trainingReqUser($company, 'people_employee');
    trainingReqBind($hr, $employee, $company, $member, 'review:req-employee');

    return compact('tenantId', 'company', 'hr', 'hod', 'employee', 'head', 'member', 'qaMember', 'reports') + ['opsEntry' => $ops['entry'], 'qaEntry' => $qa['entry']];
}

function trainingReqStoreRequest(array $f, Employee $requestor, PeopleReferenceEntry $unit, string $need, bool $submit = true): TrainingRequest
{
    $store = app(TrainingRequestStore::class);
    $request = $store->create($f['hr'], (int) $f['company']->id, new TrainingRequestDraft(
        requestor: new WorkforceSubject($f['tenantId'], (int) $f['company']->id, WorkforceResourceType::Employee, (string) $requestor->id),
        department: new WorkforceSubject($f['tenantId'], (int) $f['company']->id, WorkforceResourceType::OrganizationUnit, (string) $unit->id),
        needSource: TrainingNeedSource::LegalCertification, need: $need, learningObjective: 'Objective.', expectedResult: 'Result.', priority: TrainingPriority::Low,
    ));

    return $submit ? $store->submit($f['hr'], (int) $f['company']->id, (int) $request->id) : $request;
}

function trainingReqRows(array $f): Collection
{
    return TrainingRequest::query()->forCompany($f['tenantId'], (int) $f['company']->id)->orderBy('id')->get();
}

test('an employee drafts for themself, submits, and the page shows the row status from the store', function (): void {
    $f = trainingReqFixture();

    $page = Livewire::actingAs($f['employee'])->test(Index::class)
        ->assertOk()
        ->assertSet('requestorEntityId', (string) $f['member']->id)
        ->set('needSource', TrainingNeedSource::NewMachineTechnology->value)
        ->set('priority', TrainingPriority::High->value)
        ->set('need', 'Operate the new press safely.')
        ->set('learningObjective', 'Run the press unsupervised.')
        ->set('expectedResult', 'Zero unsafe starts.')
        ->call('draft')
        ->assertHasNoErrors();

    $request = trainingReqRows($f)->sole();
    expect($request->status)->toBe(TrainingRequestStatus::Draft)
        ->and($request->requestor_subject_id)->toBe((string) $f['member']->id)
        ->and($request->department_subject_id)->toBe((string) $f['opsEntry']->id)
        ->and($request->created_by_user_id)->toBe($f['employee']->id)
        ->and($request->need)->toBe('Operate the new press safely.');

    $page->assertSeeHtml('data-status="draft"')->assertSee('Operations')
        ->call('submitRequest', $request->id)->assertHasNoErrors()
        ->assertSeeHtml('data-status="pending_hod"')->assertSee('submitted');

    expect($request->fresh()->status)->toBe(TrainingRequestStatus::PendingHod);
});

test('an employee naming a colleague as requestor is refused and nothing is written', function (): void {
    $f = trainingReqFixture();

    Livewire::actingAs($f['employee'])->test(Index::class)
        ->set('requestorEntityId', (string) $f['qaMember']->id)
        ->set('need', 'For a colleague.')->set('learningObjective', 'Objective.')->set('expectedResult', 'Result.')
        ->call('draft')
        ->assertForbidden();

    expect(trainingReqRows($f))->toHaveCount(0);
});

test('a HOD drafts for a department member, submits, and recommends it through the store', function (): void {
    $f = trainingReqFixture();

    $page = Livewire::actingAs($f['hod'])->test(Index::class)
        ->assertOk()
        ->set('requestorEntityId', (string) $f['member']->id)
        ->set('need', 'Certify on the new line.')->set('learningObjective', 'Objective.')->set('expectedResult', 'Result.')
        ->call('draft')->assertHasNoErrors();

    $request = trainingReqRows($f)->sole();
    expect($request->requestor_subject_id)->toBe((string) $f['member']->id)
        ->and($request->created_by_user_id)->toBe($f['hod']->id);

    $page->call('submitRequest', $request->id)->assertHasNoErrors()
        ->set('recommendNotes.'.$request->id, 'Needed this quarter.')
        ->call('recommend', $request->id)->assertHasNoErrors()
        ->assertSeeHtml('data-status="pending_hr"');

    $request->refresh();
    expect($request->status)->toBe(TrainingRequestStatus::PendingHr)
        ->and($request->decisions->pluck('decision')->all())->toBe(['created', 'submitted', 'hod_recommended'])
        ->and($request->decisions->last()->notes)->toBe('Needed this quarter.')
        ->and($request->decisions->last()->actor_user_id)->toBe($f['hod']->id);
});

test('a HOD naming an employee outside the department is refused and nothing is written', function (): void {
    $f = trainingReqFixture();

    Livewire::actingAs($f['hod'])->test(Index::class)
        ->set('requestorEntityId', (string) $f['qaMember']->id)
        ->set('need', 'Outside.')->set('learningObjective', 'Objective.')->set('expectedResult', 'Result.')
        ->call('draft')
        ->assertForbidden();

    expect(trainingReqRows($f))->toHaveCount(0);
});

test('a HOD recommends only for a department they head, and an employee never recommends', function (): void {
    $f = trainingReqFixture();
    $foreign = trainingReqStoreRequest($f, $f['qaMember'], $f['qaEntry'], 'Quality request.');
    $reports = trainingReqStoreRequest($f, $f['reports'], $f['qaEntry'], 'Reporting employee request.');
    $own = trainingReqStoreRequest($f, $f['member'], $f['opsEntry'], 'Member request.');

    // Quality is not a unit this HOD heads: its member's request is neither
    // listed nor reachable by id, and the reporting employee's request is
    // listed (the HOD may draft for them) but not theirs to recommend.
    $page = Livewire::actingAs($f['hod'])->test(Index::class)
        ->assertSee('Member request.')->assertSee('Reporting employee request.')->assertDontSee('Quality request.');
    expect($page->viewData('recommendable'))->toBe([$own->id]);
    $page->call('recommend', $reports->id)->assertForbidden();
    expect($reports->fresh()->status)->toBe(TrainingRequestStatus::PendingHod);
    Livewire::actingAs($f['hod'])->test(Index::class)->call('recommend', $foreign->id)->assertNotFound();
    expect($foreign->fresh()->status)->toBe(TrainingRequestStatus::PendingHod);

    // The employee tracks their own request but heads nothing, so the page
    // refuses before the store would.
    Livewire::actingAs($f['employee'])->test(Index::class)->assertSee('Member request.')
        ->call('recommend', $own->id)->assertForbidden();
    expect($own->fresh()->status)->toBe(TrainingRequestStatus::PendingHod)
        ->and($own->decisions()->count())->toBe(2);
});

test('each audience tracks only its own requests', function (): void {
    $f = trainingReqFixture();
    $own = trainingReqStoreRequest($f, $f['member'], $f['opsEntry'], 'Member request.');
    $foreign = trainingReqStoreRequest($f, $f['qaMember'], $f['qaEntry'], 'Quality request.');
    $headOwn = trainingReqStoreRequest($f, $f['head'], $f['opsEntry'], 'Head request.', submit: false);

    $employee = Livewire::actingAs($f['employee'])->test(Index::class);
    expect($employee->viewData('requests')->pluck('id')->all())->toBe([$own->id]);
    $employee->assertSee('Member request.')->assertDontSee('Quality request.')->assertDontSee('Head request.');

    $hod = Livewire::actingAs($f['hod'])->test(Index::class);
    expect($hod->viewData('requests')->pluck('id')->all())->toBe([$headOwn->id, $own->id])
        ->and($hod->viewData('employees'))->toHaveKeys([$f['head']->id, $f['member']->id, $f['reports']->id])
        ->and($hod->viewData('employees'))->not->toHaveKey($f['qaMember']->id)
        ->and($hod->viewData('recommendable'))->toBe([$own->id])
        ->and($hod->viewData('editable'))->toBe([$headOwn->id]);
    $hod->assertDontSee('Quality request.');

    // Submitting a draft is for its requestor's audience; the employee
    // cannot submit the head's draft even by id.
    Livewire::actingAs($f['employee'])->test(Index::class)->call('submitRequest', $headOwn->id)->assertNotFound();
    expect($headOwn->fresh()->status)->toBe(TrainingRequestStatus::Draft);
});

test('the page and its route refuse a user outside the training audiences', function (): void {
    $f = trainingReqFixture();
    $trainer = trainingReqUser($f['company'], 'people_training_trainer');

    Livewire::actingAs($trainer)->test(Index::class)->assertForbidden();
    $this->actingAs($trainer)->get(route('people.training.requests.index'))->assertForbidden();
    $this->actingAs($f['employee'])->get(route('people.training.requests.index'))->assertOk()->assertSee('Training requests');
});
