<?php

use App\Base\Authz\Enums\PrincipalType;
use App\Base\Authz\Models\PrincipalCapability;
use App\Base\Authz\Models\PrincipalRole;
use App\Base\Authz\Models\Role;
use App\Base\Tenancy\Contracts\TenantContext;
use App\Core\Company\Models\Company;
use App\Core\User\Models\User;
use App\Domains\People\Provider\Data\WorkforceSubject;
use App\Domains\People\Provider\Enums\WorkforceResourceType;
use App\Domains\People\Settings\Models\PeopleReferenceEntry;
use App\Domains\People\Skills\Tests\Support\NativeWorkforceFixture;
use App\Domains\People\Training\Data\TrainingRequestDraft;
use App\Domains\People\Training\Enums\BudgetAuditKind;
use App\Domains\People\Training\Enums\TrainingNeedSource;
use App\Domains\People\Training\Enums\TrainingPriority;
use App\Domains\People\Training\Enums\TrainingRequestStatus;
use App\Domains\People\Training\Exceptions\InvalidTrainingRequestException;
use App\Domains\People\Training\Models\TrainingDepartmentBudgetAudit;
use App\Domains\People\Training\Models\TrainingRequest;
use App\Domains\People\Training\Services\TrainingBudgetStore;
use App\Domains\People\Training\Services\TrainingRequestStore;

/**
 * 0010-b: an approval that would take a department past its training budget is
 * refused, unless somebody says in writing why it should not be.
 *
 * The budget is only worth having if approving is where it bites. A page that
 * reports an overspend after the fact reports a decision nobody was stopped
 * from making.
 *
 * Self-contained: helpers are prefixed approval and live here.
 */
afterEach(function (): void {
    app(TenantContext::class)->clear();
});

function approvalUser(Company $company, string $roleCode): User
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
function approvalFixture(string $label = 'Approval'): array
{
    [$tenant, $company] = createTenantWithCompany(
        ['name' => $label.' Tenant'],
        ['name' => $label.' Company', 'status' => 'active'],
    );
    $tenantId = (int) $tenant->id;
    $companyId = (int) $company->id;
    app(TenantContext::class)->set($tenantId);
    setupAuthzRoles();

    $hr = approvalUser($company, 'people_hr');
    $hod = approvalUser($company, 'people_hod');
    $approver = approvalUser($company, 'people_training_approver');
    $department = PeopleReferenceEntry::query()->create([
        'company_id' => $companyId, 'type' => PeopleReferenceEntry::TYPE_ORGANIZATION_UNIT,
        'code' => 'OPS-'.$label, 'name' => 'Operations '.$label, 'status' => PeopleReferenceEntry::STATUS_ACTIVE,
    ]);

    return compact('tenantId', 'companyId', 'company', 'hr', 'hod', 'approver', 'department');
}

/** The override is granted to a principal, never to a role. */
function approvalGrantOverride(array $f, User $user): void
{
    PrincipalCapability::query()->create([
        'company_id' => $f['companyId'], 'principal_type' => PrincipalType::USER->value,
        'principal_id' => $user->id, 'capability_key' => TrainingBudgetStore::OVERRIDE,
        'is_allowed' => true,
    ]);
}

function approvalBudget(array $f, string $amount): void
{
    app(TrainingBudgetStore::class)->setBudget(
        $f['hr'], $f['companyId'], (int) $f['department']->id, (int) now()->year, $amount, 'Annual allocation.',
    );
}

/** A request carried to the point of decision, not yet approved. */
function approvalRequest(array $f, ?string $cost): TrainingRequest
{
    app(TenantContext::class)->set($f['tenantId']);
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
    $store->recommend($f['hod'], $f['companyId'], (int) $request->id, 'Relevant.');
    $store->review($f['hr'], $f['companyId'], (int) $request->id, 'Checked.');

    return $request->refresh();
}

/** Spend the given amount so the department already has it approved. */
function approvalSpend(array $f, string $cost): void
{
    $request = approvalRequest($f, $cost);
    app(TrainingRequestStore::class)->approve($f['approver'], $f['companyId'], (int) $request->id, 'Approved.');
}

test('an approval that would exceed the budget is refused, naming what is left', function (): void {
    $f = approvalFixture();
    approvalBudget($f, '1000.0000');
    approvalSpend($f, '900.0000');
    $request = approvalRequest($f, '200.0000');

    // Delete the comparison and this is simply approved, which is the state
    // before this lane.
    expect(fn () => app(TrainingRequestStore::class)->approve(
        $f['approver'], $f['companyId'], (int) $request->id, 'Approved.',
    ))->toThrow(InvalidTrainingRequestException::class, 'remaining 100')
        ->and($request->fresh()->status)->toBe(TrainingRequestStatus::PendingApproval);
});

test('an approval inside the budget is untouched', function (): void {
    $f = approvalFixture();
    approvalBudget($f, '1000.0000');
    approvalSpend($f, '900.0000');
    $request = approvalRequest($f, '100.0000');

    // Exactly to the limit is inside it, not over it.
    app(TrainingRequestStore::class)->approve($f['approver'], $f['companyId'], (int) $request->id, 'Approved.');

    expect($request->fresh()->status)->toBe(TrainingRequestStatus::Approved);
});

test('an override holder with a stated reason approves and the audit records the overage', function (): void {
    $f = approvalFixture();
    approvalGrantOverride($f, $f['approver']);
    approvalBudget($f, '1000.0000');
    approvalSpend($f, '900.0000');
    $request = approvalRequest($f, '200.0000');

    app(TrainingRequestStore::class)->approve(
        $f['approver'], $f['companyId'], (int) $request->id, 'Approved.', 'Safety-critical, agreed with the plant manager.',
    );

    $override = TrainingDepartmentBudgetAudit::query()->forCompany($f['tenantId'], $f['companyId'])
        ->where('kind', BudgetAuditKind::Override)->sole();

    expect($request->fresh()->status)->toBe(TrainingRequestStatus::Approved)
        ->and($override->amount)->toBe('200.0000')
        ->and($override->overage_amount)->toBe('100.0000')
        ->and($override->reason)->toBe('Safety-critical, agreed with the plant manager.');
});

test('an override without a stated reason is refused', function (): void {
    $f = approvalFixture();
    approvalGrantOverride($f, $f['approver']);
    approvalBudget($f, '1000.0000');
    approvalSpend($f, '900.0000');
    $request = approvalRequest($f, '200.0000');

    // Holding the capability is permission to decide, not permission to say
    // nothing about it.
    expect(fn () => app(TrainingRequestStore::class)->approve(
        $f['approver'], $f['companyId'], (int) $request->id, 'Approved.', '   ',
    ))->toThrow(InvalidTrainingRequestException::class)
        ->and($request->fresh()->status)->toBe(TrainingRequestStatus::PendingApproval);
});

test('a reason without the capability does not buy an override', function (): void {
    $f = approvalFixture();
    approvalBudget($f, '1000.0000');
    approvalSpend($f, '900.0000');
    $request = approvalRequest($f, '200.0000');

    expect(fn () => app(TrainingRequestStore::class)->approve(
        $f['approver'], $f['companyId'], (int) $request->id, 'Approved.', 'I would like to.',
    ))->toThrow(InvalidTrainingRequestException::class)
        ->and(TrainingDepartmentBudgetAudit::query()->forCompany($f['tenantId'], $f['companyId'])
            ->where('kind', BudgetAuditKind::Override)->count())->toBe(0);
});

test('a department with no budget approves as before', function (): void {
    $f = approvalFixture();
    $request = approvalRequest($f, '5000.0000');

    // No allocation is not an allocation of nothing, here as on the page.
    app(TrainingRequestStore::class)->approve($f['approver'], $f['companyId'], (int) $request->id, 'Approved.');

    expect($request->fresh()->status)->toBe(TrainingRequestStatus::Approved);
});

test('a request nobody has priced is unaffected by the budget', function (): void {
    $f = approvalFixture();
    approvalBudget($f, '1000.0000');
    approvalSpend($f, '1000.0000');
    $request = approvalRequest($f, null);

    // There is nothing to compare, so there is nothing to refuse.
    app(TrainingRequestStore::class)->approve($f['approver'], $f['companyId'], (int) $request->id, 'Approved.');

    expect($request->fresh()->status)->toBe(TrainingRequestStatus::Approved);
});

test("company axis: a sibling company's spend does not constrain this approval", function (): void {
    $f = approvalFixture();
    $sibling = Company::factory()->create([
        'tenant_id' => $f['tenantId'], 'name' => 'Sibling Approval Company', 'status' => 'active',
    ]);
    $siblingFixture = array_merge($f, [
        'companyId' => (int) $sibling->id,
        'company' => $sibling,
        'hr' => approvalUser($sibling, 'people_hr'),
        'hod' => approvalUser($sibling, 'people_hod'),
        'approver' => approvalUser($sibling, 'people_training_approver'),
        'department' => PeopleReferenceEntry::query()->create([
            'company_id' => $sibling->id, 'type' => PeopleReferenceEntry::TYPE_ORGANIZATION_UNIT,
            'code' => 'OPS-SIB', 'name' => 'Operations Sibling', 'status' => PeopleReferenceEntry::STATUS_ACTIVE,
        ]),
    ]);
    approvalBudget($f, '1000.0000');
    approvalBudget($siblingFixture, '1000.0000');
    approvalSpend($siblingFixture, '1000.0000');

    // The sibling is at its limit; this company has spent nothing.
    $request = approvalRequest($f, '900.0000');
    app(TrainingRequestStore::class)->approve($f['approver'], $f['companyId'], (int) $request->id, 'Approved.');

    expect($request->fresh()->status)->toBe(TrainingRequestStatus::Approved);
});
