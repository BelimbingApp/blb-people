<?php

use App\Base\Authz\Exceptions\AuthorizationDeniedException;
use App\Base\Tenancy\Contracts\TenantContext;
use App\Core\Company\Models\Company;
use App\Core\Employee\Models\Employee;
use App\Core\User\Models\User;
use App\Domains\People\Claim\Livewire\Index;
use App\Domains\People\Claim\Models\ClaimAssignment;
use App\Domains\People\Claim\Models\ClaimAssignmentLine;
use App\Domains\People\Claim\Models\ClaimCategory;
use App\Domains\People\Claim\Models\ClaimContext;
use App\Domains\People\Claim\Models\ClaimPolicy;
use App\Domains\People\Claim\Models\ClaimPolicyBand;
use App\Domains\People\Claim\Models\ClaimRequest;
use App\Domains\People\Claim\Models\ClaimType;
use App\Domains\People\Claim\Services\SubmitClaimRequestService;
use Livewire\Livewire;

afterEach(fn () => app(TenantContext::class)->clear());

function claimActionFixture(): array
{
    $user = createAdminUser();
    $employee = Employee::factory()->create(['company_id' => $user->company_id, 'status' => 'active']);
    $user->update(['employee_id' => $employee->id]);
    test()->actingAs($user);
    $type = ClaimType::query()->create([
        'company_id' => $user->company_id, 'code' => 'fixture', 'name' => 'Fixture type',
        'default_unit' => 'amount', 'calculation_mode' => 'manual_amount',
        'receipt_requirement' => 'never', 'provider_required' => false,
        'payroll_eligible' => false, 'allow_employee_submission' => true, 'status' => 'active',
    ]);
    $policy = ClaimPolicy::query()->create([
        'company_id' => $user->company_id, 'code' => 'fixture', 'name' => 'Fixture policy',
        'item_mode' => 'single_value', 'effective_from' => '2026-01-01',
        'encumber_pending' => false, 'version' => 1, 'status' => 'active',
    ]);
    ClaimPolicyBand::query()->create([
        'claim_policy_id' => $policy->id, 'logical_operator' => '<=', 'rate' => 1,
        'per_claim_limit' => 500, 'sort_order' => 10,
    ]);
    $assignment = ClaimAssignment::query()->create([
        'company_id' => $user->company_id, 'code' => 'fixture', 'name' => 'Fixture assignment',
        'effective_from' => '2026-01-01', 'status' => 'active',
    ]);
    $line = ClaimAssignmentLine::query()->create([
        'claim_assignment_id' => $assignment->id, 'claim_type_id' => $type->id,
        'claim_policy_id' => $policy->id, 'status' => 'active', 'sort_order' => 10,
    ]);

    return compact('user', 'employee', 'type', 'policy', 'assignment', 'line');
}

function claimActionRequest(array $f): ClaimRequest
{
    return app(SubmitClaimRequestService::class)->submit(
        $f['employee'], $f['assignment'], $f['line'], new DateTimeImmutable('2026-06-10'), 42.50,
    );
}

function claimSetupPayload(string $action, array $f): array
{
    return match ($action) {
        'createCategory' => ['categoryCode' => 'Travel New', 'categoryName' => 'Travel'],
        'createClaimType' => ['typeCode' => 'Travel New', 'typeName' => 'Travel', 'typeReceiptRequirement' => 'never'],
        'createPolicy' => ['policyCode' => 'Travel New', 'policyName' => 'Travel', 'policyEffectiveFrom' => '2026-02-01'],
        'addPolicyBand' => ['bandPolicyId' => (string) $f['policy']->id, 'bandRate' => '2.5', 'bandPerClaimLimit' => '123'],
        'createAssignment' => ['assignmentCode' => 'Travel New', 'assignmentName' => 'Travel', 'assignmentEffectiveFrom' => '2026-02-01'],
        'addAssignmentLine' => ['lineAssignmentId' => (string) $f['assignment']->id, 'lineClaimTypeId' => (string) $f['type']->id, 'lineClaimPolicyId' => (string) $f['policy']->id, 'lineCombineTag' => 'travel-cap'],
        'createContext' => ['contextCode' => 'Travel New', 'contextLabel' => 'Travel', 'contextMaxClaimLimit' => '123'],
    };
}

dataset('claim setup actions', [
    ['createCategory', ClaimCategory::class, 'categoryName', 'name', 'Travel'],
    ['createClaimType', ClaimType::class, 'typeName', 'name', 'Travel'],
    ['createPolicy', ClaimPolicy::class, 'policyName', 'name', 'Travel'],
    ['addPolicyBand', ClaimPolicyBand::class, 'bandRate', 'rate', '2.5000'],
    ['createAssignment', ClaimAssignment::class, 'assignmentName', 'name', 'Travel'],
    ['addAssignmentLine', ClaimAssignmentLine::class, 'lineClaimPolicyId', 'combine_tag', 'travel-cap'],
    ['createContext', ClaimContext::class, 'contextLabel', 'label', 'Travel'],
]);

it('persists an authorized claim setup action', function ($action, $model, $invalid, $field, $expected): void {
    $f = claimActionFixture();
    Livewire::test(Index::class, ['surface' => 'settings'])
        ->set(claimSetupPayload($action, $f))->call($action)->assertHasNoErrors()
        ->assertDispatched('notify', variant: 'success');
    $record = $model::query()->latest('id')->firstOrFail();
    expect((string) $record->$field)->toBe($expected);
    if (in_array($action, ['addPolicyBand', 'addAssignmentLine'], true)) {
        expect($record->claim_policy_id)->toBe($f['policy']->id);
    } else {
        expect($record->company_id)->toBe($f['user']->company_id)
            ->and($record->code)->toBe('travel_new');
    }
})->with('claim setup actions');

it('denies each setup action without manage capability before writing', function ($action, $model): void {
    $f = claimActionFixture();
    $before = $model::query()->get()->toArray();
    $this->actingAs(User::factory()->create(['company_id' => $f['user']->company_id]));
    expect(fn () => Livewire::test(Index::class, ['surface' => 'settings'])
        ->set(claimSetupPayload($action, $f))->call($action))
        ->toThrow(AuthorizationDeniedException::class);
    expect($model::query()->get()->toArray())->toBe($before);
})->with('claim setup actions');

it('validates each setup action before writing', function ($action, $model, $invalid): void {
    $f = claimActionFixture();
    $before = $model::query()->get()->toArray();
    Livewire::test(Index::class, ['surface' => 'settings'])
        ->set(claimSetupPayload($action, $f))->set($invalid, '')->call($action)
        ->assertHasErrors([$invalid => 'required']);
    expect($model::query()->get()->toArray())->toBe($before);
})->with('claim setup actions');

dataset('claim lifecycle actions', [
    ['approveRequest', 'submitted', 'approved'],
    ['rejectRequest', 'submitted', 'rejected'],
    ['requestMoreInfo', 'submitted', 'needs_more_info'],
    ['markReimbursed', 'approved', 'reimbursed'],
    ['cancelRequest', 'submitted', 'cancelled'],
    ['withdrawOwnRequest', 'submitted', 'withdrawn'],
]);

it('performs each authorized claim lifecycle action and records its actor', function ($action, $initial, $expected): void {
    $f = claimActionFixture();
    $request = claimActionRequest($f);
    $request->update(['status' => $initial]);
    if ($initial === 'approved') {
        $request->lines()->update(['approved_amount' => 42.50]);
    }
    Livewire::test(Index::class)->set('approvalReason', 'Verified receipt')->call($action, $request->id)
        ->assertDispatched('notify', variant: 'success');
    expect($request->refresh()->status)->toBe($expected);
    $audit = $request->auditEvents()->latest('id')->firstOrFail();
    expect($audit->actor_user_id)->toBe($f['user']->id)->and($audit->to_status)->toBe($expected);
    if ($action === 'markReimbursed') {
        expect((float) $request->reimbursed_amount)->toBe(42.50);
    }
})->with('claim lifecycle actions');

it('refuses another companys request for every lifecycle action', function ($action, $initial): void {
    $f = claimActionFixture();
    $request = claimActionRequest($f);
    $company = Company::factory()->create(['tenant_id' => $f['employee']->company->tenant_id]);
    $request->update(['status' => $initial, 'company_id' => $company->id]);
    $before = $request->getAttributes();
    $audits = $request->auditEvents()->count();
    Livewire::test(Index::class)->call($action, $request->id)
        ->assertDispatched('notify', variant: 'error');
    expect($request->fresh()->getAttributes())->toBe($before)
        ->and($request->auditEvents()->count())->toBe($audits);
})->with('claim lifecycle actions');

it('denies lifecycle actions without capability or employee ownership', function ($action, $initial): void {
    $f = claimActionFixture();
    $request = claimActionRequest($f);
    $request->update(['status' => $initial]);
    $other = Employee::factory()->create(['company_id' => $f['user']->company_id]);
    $this->actingAs(User::factory()->create(['company_id' => $f['user']->company_id, 'employee_id' => $other->id]));
    if ($action === 'withdrawOwnRequest') {
        Livewire::test(Index::class)->call($action, $request->id)->assertDispatched('notify', variant: 'error');
    } else {
        expect(fn () => Livewire::test(Index::class)->call($action, $request->id))
            ->toThrow(AuthorizationDeniedException::class);
    }
    expect($request->fresh()->status)->toBe($initial);
})->with('claim lifecycle actions');

function claimSubmissionPayload(array $f): array
{
    return [
        'applyAssignmentId' => (string) $f['assignment']->id,
        'applyAssignmentLineId' => (string) $f['line']->id,
        'applyIncurredOn' => '2026-06-10', 'applyRequestedAmount' => '42.50',
        'applyDescription' => 'Taxi receipt', 'showClaimModal' => true,
    ];
}

it('submits the linked employees claim and resets the submitted form', function (): void {
    $f = claimActionFixture();
    Livewire::test(Index::class)->set(claimSubmissionPayload($f))->call('submitClaim')
        ->assertHasNoErrors()->assertDispatched('notify', variant: 'success')
        ->assertSet('showClaimModal', false)->assertSet('applyRequestedAmount', '');
    $request = ClaimRequest::query()->sole();
    expect($request->employee_id)->toBe($f['employee']->id)
        ->and($request->company_id)->toBe($f['user']->company_id)
        ->and((float) $request->requested_amount)->toBe(42.50)
        ->and($request->status)->toBe('submitted');
});

it('refuses submission for an unlinked user', function (): void {
    $f = claimActionFixture();
    $f['user']->update(['employee_id' => null]);
    Livewire::test(Index::class)->set(claimSubmissionPayload($f))->call('submitClaim')
        ->assertDispatched('notify', message: 'Your user account is not linked to an employee record.', variant: 'error');
    expect(ClaimRequest::query()->count())->toBe(0);
});

it('validates submission amount without creating a request', function (): void {
    $f = claimActionFixture();
    Livewire::test(Index::class)->set(claimSubmissionPayload($f))->set('applyRequestedAmount', '0')
        ->call('submitClaim')->assertHasErrors(['applyRequestedAmount' => 'min']);
    expect(ClaimRequest::query()->count())->toBe(0);
});

it('selects an in-company request but never renders a sibling company request', function (): void {
    $f = claimActionFixture();
    $request = claimActionRequest($f);
    Livewire::test(Index::class)->call('selectRequest', $request->id)
        ->assertSet('selectedRequestId', $request->id)
        ->assertViewHas('selectedRequest', fn ($selected) => $selected?->id === $request->id);
    $company = Company::factory()->create(['tenant_id' => $f['employee']->company->tenant_id]);
    $request->update(['company_id' => $company->id]);
    Livewire::test(Index::class)->call('selectRequest', $request->id)
        ->assertViewHas('selectedRequest', fn ($selected) => $selected === null);
});

it('keeps tab navigation inside the current surface and clears selection only on allowed navigation', function (): void {
    claimActionFixture();
    Livewire::test(Index::class)->set('selectedRequestId', 123)
        ->call('setTab', 'approvals')->assertSet('tab', 'submit')->assertSet('selectedRequestId', 123)
        ->call('setTab', 'submit')->assertSet('selectedRequestId', null);
});

it('normalizes the updated search before filtering claim setup records', function (): void {
    $f = claimActionFixture();
    ClaimCategory::query()->create(['company_id' => $f['user']->company_id, 'code' => 'travel', 'name' => 'Travel']);
    ClaimCategory::query()->create(['company_id' => $f['user']->company_id, 'code' => 'medical', 'name' => 'Medical']);
    Livewire::test(Index::class, ['surface' => 'settings'])->set('search', '  travel  ')
        ->assertSet('search', 'travel')
        ->assertViewHas('categories', fn ($categories) => $categories->pluck('code')->all() === ['travel']);
});
