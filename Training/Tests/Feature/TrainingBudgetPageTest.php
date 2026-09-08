<?php

use App\Base\Authz\Enums\PrincipalType;
use App\Base\Authz\Exceptions\AuthorizationDeniedException;
use App\Base\Authz\Models\PrincipalRole;
use App\Base\Authz\Models\Role;
use App\Base\Menu\Contracts\MenuAccessChecker;
use App\Base\Menu\MenuItem;
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
use App\Domains\People\Skills\Models\SkillActorBinding;
use App\Domains\People\Skills\Tests\Support\NativeWorkforceFixture;
use App\Domains\People\Training\Data\TrainingRequestDraft;
use App\Domains\People\Training\Enums\TrainingNeedSource;
use App\Domains\People\Training\Enums\TrainingPriority;
use App\Domains\People\Training\Exceptions\InvalidTrainingBudgetException;
use App\Domains\People\Training\Livewire\Budget\Index;
use App\Domains\People\Training\Models\TrainingDepartmentBudget;
use App\Domains\People\Training\Models\TrainingDepartmentBudgetAudit;
use App\Domains\People\Training\Services\TrainingBudgetStore;
use App\Domains\People\Training\Services\TrainingRequestStore;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

/**
 * Training budget page (0010-a): what a department has committed against what
 * it was given, for one year, in one company.
 *
 * Money is decimal(19,4) like Payroll's amounts, and every total is summed with
 * bcadd rather than float addition — a budget page that is a cent out is a
 * budget page nobody trusts, and SQLite and PostgreSQL do not agree about what
 * SUM() of a decimal returns.
 *
 * Self-contained: helpers are prefixed budget and live here.
 */
afterEach(function (): void {
    Carbon::setTestNow();
    app(TenantContext::class)->clear();
});

beforeEach(function (): void {
    $this->withoutVite();
});

function budgetUser(Company $company, string $roleCode): User
{
    $user = User::factory()->create(['company_id' => $company->id]);
    PrincipalRole::query()->create([
        'company_id' => $company->id, 'principal_type' => PrincipalType::USER->value,
        'principal_id' => $user->id,
        'role_id' => Role::query()->whereNull('company_id')->where('code', $roleCode)->sole()->id,
    ]);

    return $user;
}

/** @return array<string, mixed> */
function budgetFixture(string $label = 'Budget'): array
{
    [$tenant, $company] = createTenantWithCompany(
        ['name' => $label.' Tenant'],
        ['name' => $label.' Company', 'status' => 'active'],
    );
    $tenantId = (int) $tenant->id;
    $companyId = (int) $company->id;
    app(TenantContext::class)->set($tenantId);
    setupAuthzRoles();

    $hr = budgetUser($company, 'people_hr');
    // A HOD may read a budget but never set one, which is what makes them the
    // right actor for the refusal test.
    $viewer = budgetUser($company, 'people_hod');
    $approver = budgetUser($company, 'people_training_approver');
    $department = PeopleReferenceEntry::query()->create([
        'company_id' => $companyId, 'type' => PeopleReferenceEntry::TYPE_ORGANIZATION_UNIT,
        'code' => 'OPS-'.$label, 'name' => 'Operations '.$label, 'status' => PeopleReferenceEntry::STATUS_ACTIVE,
    ]);

    return compact('tenantId', 'companyId', 'company', 'hr', 'viewer', 'approver', 'department');
}

function budgetBindHod(array $fixture): void
{
    $type = DepartmentType::query()->create([
        'code' => 'budget-ops-'.$fixture['companyId'],
        'name' => 'Budget operations',
        'category' => 'operational',
        'is_active' => true,
    ]);
    $department = Department::query()->create([
        'company_id' => $fixture['companyId'],
        'department_type_id' => $type->id,
        'status' => 'active',
    ]);
    $head = Employee::factory()->create([
        'company_id' => $fixture['companyId'],
        'department_id' => $department->id,
        'full_name' => 'Budget operations head',
        'status' => 'active',
    ]);
    $department->update(['head_id' => $head->id]);
    EmployeeWorkProfile::query()->create([
        'employee_id' => $head->id,
        'organization_unit_id' => $fixture['department']->id,
    ]);
    $fixture['viewer']->update(['employee_id' => $head->id]);
    EmployeePortalAccess::query()->create([
        'employee_id' => $head->id,
        'user_id' => $fixture['viewer']->id,
        'display_name' => $head->full_name,
        'status' => EmployeePortalAccess::STATUS_ACTIVE,
    ]);
    SkillActorBinding::query()->create([
        'tenant_id' => $fixture['tenantId'],
        'company_entity_id' => $fixture['companyId'],
        'platform_user_id' => $fixture['viewer']->id,
        'employee_entity_id' => $head->id,
        'user_entity_id' => $fixture['viewer']->id,
        'confirmed_by_user_id' => $fixture['hr']->id,
        'review_reference' => 'training-budget-test',
        'confirmed_at' => now(),
    ]);
}

/** A request at the given status, costing the given amount. */
function budgetRequest(array $f, string $status, ?string $cost, ?string $createdAt = null): void
{
    // Pin the context to this fixture's tenant: a test with two tenants leaves
    // it on whichever was built last, and the store refuses across companies.
    app(TenantContext::class)->set($f['tenantId']);
    Carbon::setTestNow($createdAt ?? now()->toDateTimeString());
    $employee = NativeWorkforceFixture::create($f['tenantId'], WorkforceResourceType::Employee, $f['companyId']);
    $store = app(TrainingRequestStore::class);
    $request = $store->create($f['hr'], $f['companyId'], new TrainingRequestDraft(
        requestor: new WorkforceSubject($f['tenantId'], $f['companyId'], WorkforceResourceType::Employee, (string) $employee->id),
        department: new WorkforceSubject($f['tenantId'], $f['companyId'], WorkforceResourceType::OrganizationUnit, (string) $f['department']->id),
        needSource: TrainingNeedSource::NewMachineTechnology,
        need: 'Operate the new control system.',
        learningObjective: 'Operate it safely.',
        expectedResult: 'Zero unsafe startups.',
        priority: TrainingPriority::High,
        estimatedCost: $cost,
    ));
    $store->submit($f['hr'], $f['companyId'], (int) $request->id);

    // Approval is four steps and four capabilities, not one: the HOD
    // recommends, HR reviews, and only the approver role decides.
    if ($status === 'approved') {
        $store->recommend($f['viewer'], $f['companyId'], (int) $request->id, 'Relevant.');
        $store->review($f['hr'], $f['companyId'], (int) $request->id, 'Checked.');
        $store->approve($f['approver'], $f['companyId'], (int) $request->id, 'Approved.');
    }
    if ($status === 'rejected') {
        $store->reject($f['viewer'], $f['companyId'], (int) $request->id, 'Not this year.');
    }
    if ($status === 'cancelled') {
        $store->cancel($f['hr'], $f['companyId'], (int) $request->id, 'Withdrawn.');
    }
    Carbon::setTestNow();
}

function budgetRow(array $f, ?int $year = null): object
{
    app(TenantContext::class)->set($f['tenantId']);
    $rows = app(TrainingBudgetStore::class)->rollUp($f['hr'], $f['companyId'], $year ?? (int) now()->year);

    return collect($rows)->firstOrFail(
        static fn (object $row): bool => $row->departmentEntityId === (int) $f['department']->id,
    );
}

test('approved and pending requests roll up separately for the department', function (): void {
    $f = budgetFixture();
    budgetRequest($f, 'approved', '1200.0000');
    budgetRequest($f, 'approved', '800.5000');
    budgetRequest($f, 'pending', '300.2500');

    $row = budgetRow($f);

    // Delete the status filter and the pending 300.25 lands in approved.
    expect($row->approved)->toBe('2000.5000')
        ->and($row->pending)->toBe('300.2500');
});

test('a rejected or cancelled request counts as neither', function (): void {
    $f = budgetFixture();
    budgetRequest($f, 'approved', '100.0000');
    budgetRequest($f, 'rejected', '9999.0000');
    budgetRequest($f, 'cancelled', '5555.0000');

    // Money that will never be spent is not money committed.
    $row = budgetRow($f);
    expect($row->approved)->toBe('100.0000')
        ->and($row->pending)->toBe('0.0000');
});

test('a request from another year is not counted', function (): void {
    $f = budgetFixture();
    budgetRequest($f, 'approved', '100.0000');
    budgetRequest($f, 'approved', '700.0000', now()->subYear()->toDateTimeString());

    expect(budgetRow($f)->approved)->toBe('100.0000');
});

test('a department with no budget reports no remaining amount rather than zero', function (): void {
    $f = budgetFixture();
    budgetRequest($f, 'approved', '100.0000');

    // No budget set is not the same as a budget of nothing: one is unknown,
    // the other is overspent by 100.
    $row = budgetRow($f);
    expect($row->budget)->toBeNull()
        ->and($row->remaining)->toBeNull();
});

test('remaining is the budget less what is already approved', function (): void {
    $f = budgetFixture();
    app(TrainingBudgetStore::class)->setBudget(
        $f['hr'], $f['companyId'], (int) $f['department']->id, (int) now()->year, '5000.0000', 'Annual allocation.',
    );
    budgetRequest($f, 'approved', '1200.0000');
    budgetRequest($f, 'pending', '900.0000');

    // Pending is shown but not deducted: it is not committed until approved.
    $row = budgetRow($f);
    expect($row->budget)->toBe('5000.0000')
        ->and($row->remaining)->toBe('3800.0000');
});

test('changing the budget writes one audit row carrying the old and new amounts', function (): void {
    $f = budgetFixture();
    $store = app(TrainingBudgetStore::class);
    $year = (int) now()->year;
    $store->setBudget($f['hr'], $f['companyId'], (int) $f['department']->id, $year, '5000.0000', 'Annual allocation.');
    $store->setBudget($f['hr'], $f['companyId'], (int) $f['department']->id, $year, '6500.0000', 'Extra intake.');

    $budgets = TrainingDepartmentBudget::query()->forCompany($f['tenantId'], $f['companyId'])->get();
    $audits = TrainingDepartmentBudgetAudit::query()->forCompany($f['tenantId'], $f['companyId'])->orderBy('id')->get();

    // One budget, two audit rows: the record is the amount now, the audit is
    // how it got there.
    expect($budgets)->toHaveCount(1)
        ->and($budgets->first()->amount)->toBe('6500.0000')
        ->and($audits)->toHaveCount(2)
        ->and($audits->first()->previous_amount)->toBeNull()
        ->and($audits->first()->amount)->toBe('5000.0000')
        ->and($audits->last()->previous_amount)->toBe('5000.0000')
        ->and($audits->last()->amount)->toBe('6500.0000')
        ->and($audits->last()->reason)->toBe('Extra intake.');
});

test('a viewer without the manage capability cannot change the budget', function (): void {
    $f = budgetFixture();

    expect(fn () => app(TrainingBudgetStore::class)->setBudget(
        $f['viewer'], $f['companyId'], (int) $f['department']->id, (int) now()->year, '5000.0000', 'Trying it on.',
    ))->toThrow(AuthorizationDeniedException::class)
        ->and(TrainingDepartmentBudget::query()->forCompany($f['tenantId'], $f['companyId'])->count())->toBe(0);
});

test('a core administrator without a People audience cannot read or write budgets', function (): void {
    $f = budgetFixture();
    $platformAdmin = budgetUser($f['company'], 'core_admin');
    $store = app(TrainingBudgetStore::class);

    expect(fn () => $store->rollUp($platformAdmin, $f['companyId'], (int) now()->year))
        ->toThrow(AuthorizationDeniedException::class)
        ->and(fn () => $store->setBudget(
            $platformAdmin,
            $f['companyId'],
            (int) $f['department']->id,
            (int) now()->year,
            '5000.0000',
            'Platform administration is not HR authority.',
        ))->toThrow(AuthorizationDeniedException::class)
        ->and(TrainingDepartmentBudget::query()->forCompany($f['tenantId'], $f['companyId'])->count())->toBe(0);

    $this->actingAs($platformAdmin)
        ->get(route('people.training.budget.index'))
        ->assertForbidden();
});

test('a core administrator with an explicit People HR assignment retains budget authority', function (): void {
    $f = budgetFixture();
    $platformAdmin = budgetUser($f['company'], 'core_admin');
    PrincipalRole::query()->create([
        'company_id' => $f['companyId'],
        'principal_type' => PrincipalType::USER->value,
        'principal_id' => $platformAdmin->id,
        'role_id' => Role::query()->whereNull('company_id')->where('code', 'people_hr')->sole()->id,
    ]);
    $store = app(TrainingBudgetStore::class);

    $store->setBudget(
        $platformAdmin,
        $f['companyId'],
        (int) $f['department']->id,
        (int) now()->year,
        '5000.0000',
        'Explicit People HR authority.',
    );

    expect($store->rollUp($platformAdmin, $f['companyId'], (int) now()->year))
        ->toHaveCount(1)
        ->and($store->mayManage($platformAdmin, $f['companyId']))->toBeTrue();

    $this->actingAs($platformAdmin)
        ->get(route('people.training.budget.index'))
        ->assertOk();
});

test('a HOD reads only budgets for departments they currently head', function (): void {
    $f = budgetFixture();
    budgetBindHod($f);
    $otherDepartment = PeopleReferenceEntry::query()->create([
        'company_id' => $f['companyId'],
        'type' => PeopleReferenceEntry::TYPE_ORGANIZATION_UNIT,
        'code' => 'FIN-Budget',
        'name' => 'Finance Budget',
        'status' => PeopleReferenceEntry::STATUS_ACTIVE,
    ]);
    $store = app(TrainingBudgetStore::class);
    $store->setBudget($f['hr'], $f['companyId'], (int) $f['department']->id, (int) now()->year, '5000.0000', 'Operations allocation.');
    $store->setBudget($f['hr'], $f['companyId'], (int) $otherDepartment->id, (int) now()->year, '9000.0000', 'Finance allocation.');

    $rows = $store->rollUp($f['viewer'], $f['companyId'], (int) now()->year);

    expect($rows)->toHaveCount(1)
        ->and($rows[0]->departmentEntityId)->toBe((int) $f['department']->id)
        ->and($rows[0]->budget)->toBe('5000.0000');
});

test('the budget menu uses the same People audience boundary as the page', function (): void {
    $f = budgetFixture();
    $platformAdmin = budgetUser($f['company'], 'core_admin');
    $item = collect((require __DIR__.'/../../Config/menu.php')['items'])
        ->firstWhere('id', 'people.training-budget');
    $menu = MenuItem::fromArray($item);
    $checker = app(MenuAccessChecker::class);

    expect($item['condition'] ?? null)->toBe('people.training.budget-audience')
        ->and($checker->canView($menu, $platformAdmin))->toBeFalse()
        ->and($checker->canView($menu, $f['hr']))->toBeTrue();
});

test("another company's requests never appear", function (): void {
    $f = budgetFixture();
    $other = budgetFixture('Other Budget');
    budgetRequest($f, 'approved', '100.0000');
    budgetRequest($other, 'approved', '4200.0000');

    expect(budgetRow($f)->approved)->toBe('100.0000');
});

test('the page renders the roll-up for a user who may view it', function (): void {
    $f = budgetFixture();
    app(TrainingBudgetStore::class)->setBudget(
        $f['hr'], $f['companyId'], (int) $f['department']->id, (int) now()->year, '5000.0000', 'Annual allocation.',
    );
    budgetRequest($f, 'approved', '1200.0000');

    Livewire::actingAs($f['hr'])->test(Index::class, ['companyEntityId' => $f['companyId']])
        ->assertOk()
        ->assertSee('Operations Budget')
        ->assertSee('3800');
});

test('a request nobody has priced adds nothing but still lists its department', function (): void {
    $f = budgetFixture();
    budgetRequest($f, 'approved', null);

    // An unpriced request is an unknown, not a cost. It must not be added as
    // one, and the department must not vanish from the page because of it:
    // "three requests and no costings" is the state HR most needs to see.
    $row = budgetRow($f);
    expect($row->approved)->toBe('0.0000')
        ->and($row->departmentEntityId)->toBe((int) $f['department']->id);
});

test('a department of another company cannot be given a budget', function (): void {
    $f = budgetFixture();
    $other = budgetFixture('Foreign Budget');
    app(TenantContext::class)->set($f['tenantId']);

    expect(fn () => app(TrainingBudgetStore::class)->setBudget(
        $f['hr'], $f['companyId'], (int) $other['department']->id, (int) now()->year, '5000.0000', 'Wrong company.',
    ))->toThrow(InvalidTrainingBudgetException::class)
        ->and(TrainingDepartmentBudget::query()->forCompany($f['tenantId'], $f['companyId'])->count())->toBe(0);
});

test('a negative budget is refused', function (): void {
    $f = budgetFixture();

    expect(fn () => app(TrainingBudgetStore::class)->setBudget(
        $f['hr'], $f['companyId'], (int) $f['department']->id, (int) now()->year, '-1.0000', 'Clawback.',
    ))->toThrow(InvalidTrainingBudgetException::class);
});

test('a budget change with no stated reason is refused', function (): void {
    $f = budgetFixture();

    // The audit is only worth keeping if every row says why.
    expect(fn () => app(TrainingBudgetStore::class)->setBudget(
        $f['hr'], $f['companyId'], (int) $f['department']->id, (int) now()->year, '5000.0000', '   ',
    ))->toThrow(InvalidTrainingBudgetException::class);
});

test('an audit row cannot be rewritten or deleted', function (): void {
    $f = budgetFixture();
    app(TrainingBudgetStore::class)->setBudget(
        $f['hr'], $f['companyId'], (int) $f['department']->id, (int) now()->year, '5000.0000', 'Annual allocation.',
    );
    $audit = TrainingDepartmentBudgetAudit::query()->forCompany($f['tenantId'], $f['companyId'])->sole();

    expect(fn () => $audit->update(['amount' => '9999.0000']))
        ->toThrow(InvalidTrainingBudgetException::class)
        ->and(fn () => $audit->delete())
        ->toThrow(InvalidTrainingBudgetException::class);
});

test('company axis: a sibling company in the same tenant is not rolled up', function (): void {
    $f = budgetFixture();
    // Same tenant, second company. The tenant axis cannot separate these, so
    // this is the test that actually exercises the company scope — the
    // two-tenant test above passes even with the company filter removed.
    $sibling = Company::factory()->create([
        'tenant_id' => $f['tenantId'], 'name' => 'Sibling Budget Company', 'status' => 'active',
    ]);
    $siblingFixture = array_merge($f, [
        'companyId' => (int) $sibling->id,
        'company' => $sibling,
        'hr' => budgetUser($sibling, 'people_hr'),
        'viewer' => budgetUser($sibling, 'people_hod'),
        'approver' => budgetUser($sibling, 'people_training_approver'),
        'department' => PeopleReferenceEntry::query()->create([
            'company_id' => $sibling->id, 'type' => PeopleReferenceEntry::TYPE_ORGANIZATION_UNIT,
            'code' => 'OPS-SIB', 'name' => 'Operations Sibling', 'status' => PeopleReferenceEntry::STATUS_ACTIVE,
        ]),
    ]);

    budgetRequest($f, 'approved', '100.0000');
    budgetRequest($siblingFixture, 'approved', '4200.0000');
    // Give only the sibling an allocation, so the budget lookup's company
    // scope is exercised as well as the request query's.
    app(TenantContext::class)->set($f['tenantId']);
    app(TrainingBudgetStore::class)->setBudget(
        $siblingFixture['hr'], $siblingFixture['companyId'], (int) $siblingFixture['department']->id,
        (int) now()->year, '9000.0000', 'Sibling allocation.',
    );

    $rows = app(TrainingBudgetStore::class)->rollUp($f['hr'], $f['companyId'], (int) now()->year);

    expect($rows)->toHaveCount(1)
        ->and($rows[0]->departmentEntityId)->toBe((int) $f['department']->id)
        ->and($rows[0]->approved)->toBe('100.0000')
        ->and($rows[0]->budget)->toBeNull();
});
