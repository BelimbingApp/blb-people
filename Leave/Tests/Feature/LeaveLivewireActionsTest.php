<?php

use App\Base\Authz\Exceptions\AuthorizationDeniedException;
use App\Base\Tenancy\Contracts\TenantContext;
use App\Core\Company\Models\Company;
use App\Core\Employee\Models\Employee;
use App\Core\User\Models\User;
use App\Domains\People\Leave\Data\LeaveLedgerEntryData;
use App\Domains\People\Leave\Data\LeaveLedgerEntrySource;
use App\Domains\People\Leave\Data\LeaveLedgerEntrySubject;
use App\Domains\People\Leave\Livewire\Index;
use App\Domains\People\Leave\Models\LeaveAssignment;
use App\Domains\People\Leave\Models\LeaveBalanceLedgerEntry;
use App\Domains\People\Leave\Models\LeaveEntitlementPolicy;
use App\Domains\People\Leave\Models\LeaveEntitlementPolicyBand;
use App\Domains\People\Leave\Models\LeaveRequest;
use App\Domains\People\Leave\Models\LeaveRequestPolicy;
use App\Domains\People\Leave\Models\LeaveType;
use App\Domains\People\Leave\Services\LeaveBalanceLedgerService;
use App\Domains\People\Leave\Services\SubmitLeaveRequestService;
use Livewire\Livewire;

afterEach(fn () => app(TenantContext::class)->clear());

function leaveActionFixture(): array
{
    $user = createAdminUser();
    $employee = Employee::factory()->create(['company_id' => $user->company_id, 'status' => 'active']);
    $user->update(['employee_id' => $employee->id]);
    test()->actingAs($user);
    $type = LeaveType::query()->create([
        'company_id' => $user->company_id, 'code' => 'action', 'name' => 'Action leave',
        'paid' => true, 'default_unit' => 'day', 'default_approval_depth' => 1,
        'interacts_with_payroll' => false, 'status' => 'active',
    ]);
    $entitlement = LeaveEntitlementPolicy::query()->create([
        'company_id' => $user->company_id, 'leave_type_id' => $type->id,
        'code' => 'action', 'name' => 'Action entitlement',
        'accrual_method' => LeaveEntitlementPolicy::ACCRUAL_ANNUAL_LUMP_NO_PRORATE,
        'entitlement_rounding' => LeaveEntitlementPolicy::ROUNDING_NONE,
        'bring_forward_cap_days' => 7, 'bring_forward_expiry_month' => 3,
        'bring_forward_anchor' => LeaveEntitlementPolicy::ANCHOR_YEAR_START,
        'effective_from' => '2026-01-01', 'version' => 1, 'status' => 'active',
    ]);
    $policy = LeaveRequestPolicy::query()->create([
        'company_id' => $user->company_id, 'leave_type_id' => $type->id,
        'code' => 'action', 'name' => 'Action request', 'allow_negative_balance' => true, 'back_date' => ['allowed' => true],
        'exclude_holiday_from_count' => false, 'exclude_off_day_from_count' => false,
        'exclude_rest_day_from_count' => false, 'effective_from' => '2026-01-01',
        'version' => 1, 'status' => 'active',
    ]);
    $assignment = LeaveAssignment::query()->create([
        'company_id' => $user->company_id, 'code' => 'action', 'name' => 'Action assignment',
        'leave_type_id' => $type->id, 'leave_entitlement_policy_id' => $entitlement->id,
        'leave_request_policy_id' => $policy->id, 'effective_from' => '2026-01-01', 'status' => 'active',
    ]);

    return compact('user', 'employee', 'type', 'entitlement', 'policy', 'assignment');
}

function leaveActionRequest(array $f): LeaveRequest
{
    return app(SubmitLeaveRequestService::class)->submit(
        $f['employee'], $f['assignment'], new DateTimeImmutable('today +30 days'), new DateTimeImmutable('today +31 days'), 'day',
    );
}

function leaveActionOpening(array $f): void
{
    app(LeaveBalanceLedgerService::class)->record(new LeaveLedgerEntryData(
        new LeaveLedgerEntrySubject($f['user']->company_id, $f['employee']->id, $f['type']->id, 2026),
        'opening', 10, 'day', new LeaveLedgerEntrySource('manual_adjustment'),
    ));
}

function leaveSetupPayload(string $action, array $f): array
{
    return match ($action) {
        'createLeaveType' => ['typeCode' => 'new_leave', 'typeName' => 'New leave'],
        'createEntitlementPolicy' => ['entitlementLeaveTypeId' => (string) $f['type']->id, 'entitlementCode' => 'new', 'entitlementName' => 'New policy'],
        'createRequestPolicy' => ['requestLeaveTypeId' => (string) $f['type']->id, 'requestCode' => 'new', 'requestName' => 'New policy'],
        'createAssignment' => ['assignmentCode' => 'new', 'assignmentName' => 'New assignment', 'assignmentLeaveTypeId' => (string) $f['type']->id, 'assignmentEntitlementPolicyId' => (string) $f['entitlement']->id, 'assignmentRequestPolicyId' => (string) $f['policy']->id],
        'addEntitlementBand' => ['bandPolicyId' => (string) $f['entitlement']->id, 'bandDays' => '21'],
        'recordAdjustment' => ['adjustmentEmployeeId' => (string) $f['employee']->id, 'adjustmentLeaveTypeId' => (string) $f['type']->id, 'adjustmentQuantity' => '3.5', 'adjustmentYear' => 2026, 'adjustmentNote' => 'Approved adjustment'],
    };
}

dataset('leave setup actions', [
    ['createLeaveType', LeaveType::class, 'typeName', 'name', 'New leave'],
    ['createEntitlementPolicy', LeaveEntitlementPolicy::class, 'entitlementName', 'name', 'New policy'],
    ['createRequestPolicy', LeaveRequestPolicy::class, 'requestName', 'name', 'New policy'],
    ['createAssignment', LeaveAssignment::class, 'assignmentName', 'name', 'New assignment'],
    ['addEntitlementBand', LeaveEntitlementPolicyBand::class, 'bandDays', 'entitlement_days', '21.0000'],
    ['recordAdjustment', LeaveBalanceLedgerEntry::class, 'adjustmentQuantity', 'quantity', '3.5000'],
]);

it('persists authorized leave setup and adjustment fields', function ($action, $model, $invalid, $field, $expected): void {
    $f = leaveActionFixture();
    Livewire::test(Index::class, ['surface' => 'settings'])->set(leaveSetupPayload($action, $f))
        ->call($action)->assertHasNoErrors()->assertDispatched('notify', variant: 'success');
    $record = $model::query()->latest('id')->firstOrFail();
    expect((string) $record->$field)->toBe($expected);
    if ($action === 'addEntitlementBand') {
        expect($record->leave_entitlement_policy_id)->toBe($f['entitlement']->id);
    } else {
        expect($record->company_id)->toBe($f['user']->company_id);
    }
    if ($action === 'recordAdjustment') {
        expect($record->employee_id)->toBe($f['employee']->id)->and($record->recorded_by_user_id)->toBe($f['user']->id)
            ->and($record->note)->toBe('Approved adjustment')->and($record->occurred_on->toDateString())->toBe('2026-01-01');
    }
})->with('leave setup actions');

it('denies leave setup and adjustments without manage capability before writing', function ($action, $model): void {
    $f = leaveActionFixture();
    $before = $model::query()->get()->toArray();
    $this->actingAs(User::factory()->create(['company_id' => $f['user']->company_id]));
    expect(fn () => Livewire::test(Index::class, ['surface' => 'settings'])->set(leaveSetupPayload($action, $f))->call($action))
        ->toThrow(AuthorizationDeniedException::class);
    expect($model::query()->get()->toArray())->toBe($before);
})->with('leave setup actions');

it('validates leave setup and adjustments before writing', function ($action, $model, $invalid): void {
    $f = leaveActionFixture();
    $before = $model::query()->get()->toArray();
    Livewire::test(Index::class, ['surface' => 'settings'])->set(leaveSetupPayload($action, $f))
        ->set($invalid, '')->call($action)->assertHasErrors([$invalid => 'required']);
    expect($model::query()->get()->toArray())->toBe($before);
})->with('leave setup actions');

dataset('leave lifecycle actions', [
    ['approveRequest', 'applied', 'submitted'], ['rejectRequest', 'rejected', 'submitted'], ['withdrawOwnRequest', 'withdrawn', 'approved'],
]);

it('performs authorized leave lifecycle decisions', function ($action, $expected, $initial): void {
    $f = leaveActionFixture();
    $request = leaveActionRequest($f);
    $request->update(['status' => $initial]);
    Livewire::test(Index::class)->set('approvalReason', 'Reviewed evidence')->call($action, $request->id)
        ->assertDispatched('notify', variant: 'success');
    expect($request->fresh()->status)->toBe($expected);
    if ($action === 'approveRequest') {
        expect((float) LeaveBalanceLedgerEntry::query()->where('employee_id', $f['employee']->id)->where('entry_type', 'taken')->sum('quantity'))->toBe(-2.0);
    }
})->with('leave lifecycle actions');

it('refuses another companys leave request for each lifecycle decision', function ($action, $expected, $initial): void {
    $f = leaveActionFixture();
    $request = leaveActionRequest($f);
    $request->update(['status' => $initial]);
    $company = Company::factory()->create(['tenant_id' => $f['employee']->company->tenant_id]);
    $request->update(['company_id' => $company->id]);
    Livewire::test(Index::class)->call($action, $request->id)->assertDispatched('notify', variant: 'error');
    expect($request->fresh()->status)->toBe($initial);
})->with('leave lifecycle actions');

it('denies leave lifecycle decisions without capability or ownership', function ($action, $expected, $initial): void {
    $f = leaveActionFixture();
    $request = leaveActionRequest($f);
    $request->update(['status' => $initial]);
    $other = Employee::factory()->create(['company_id' => $f['user']->company_id]);
    $this->actingAs(User::factory()->create(['company_id' => $f['user']->company_id, 'employee_id' => $other->id]));
    if ($action === 'withdrawOwnRequest') {
        Livewire::test(Index::class)->call($action, $request->id)->assertDispatched('notify', variant: 'error');
    } else {
        expect(fn () => Livewire::test(Index::class)->call($action, $request->id))->toThrow(AuthorizationDeniedException::class);
    }
    expect($request->fresh()->status)->toBe($initial);
})->with('leave lifecycle actions');

function leaveApplicationPayload(array $f): array
{
    return ['applyAssignmentId' => (string) $f['assignment']->id, 'applyStartsOn' => '2026-06-10', 'applyEndsOn' => '2026-06-11', 'showApplyModal' => true];
}

it('submits the linked employees leave and closes the form', function (): void {
    $f = leaveActionFixture();
    Livewire::test(Index::class)->set(leaveApplicationPayload($f))->call('applyLeave')
        ->assertHasNoErrors()->assertDispatched('notify', variant: 'success')->assertSet('showApplyModal', false);
    $request = LeaveRequest::query()->sole();
    expect($request->employee_id)->toBe($f['employee']->id)->and($request->company_id)->toBe($f['user']->company_id)
        ->and((float) $request->quantity)->toBe(2.0)->and($request->starts_on->toDateString())->toBe('2026-06-10');
});

it('refuses leave submission for an unlinked user', function (): void {
    $f = leaveActionFixture();
    $f['user']->update(['employee_id' => null]);
    Livewire::test(Index::class)->set(leaveApplicationPayload($f))->call('applyLeave')
        ->assertDispatched('notify', message: 'Your user account is not linked to an employee record.', variant: 'error');
    expect(LeaveRequest::query()->count())->toBe(0);
});

it('refuses inverted leave dates without creating a request', function (): void {
    $f = leaveActionFixture();
    Livewire::test(Index::class)->set(leaveApplicationPayload($f))->set('applyEndsOn', '2026-06-09')
        ->call('applyLeave')->assertHasErrors(['applyEndsOn' => 'after_or_equal']);
    expect(LeaveRequest::query()->count())->toBe(0);
});

it('previews carry forward without writing and commits the selected computed amounts', function (): void {
    $f = leaveActionFixture();
    leaveActionOpening($f);
    $component = Livewire::test(Index::class, ['surface' => 'settings', 'section' => 'carry-forward'])
        ->set('carryForwardFromYear', 2026)->call('previewCarryForward');
    $preview = $component->get('carryForwardPreview');
    expect($preview)->toHaveCount(1)->and($preview[0]['employee_id'])->toBe($f['employee']->id)
        ->and((float) $preview[0]['carried'])->toBe(7.0)->and((float) $preview[0]['expired'])->toBe(3.0)
        ->and(LeaveBalanceLedgerEntry::query()->count())->toBe(1);
    $component->call('commitCarryForward')->assertDispatched('notify', variant: 'success')->assertSet('carryForwardPreview', []);
    expect((float) LeaveBalanceLedgerEntry::query()->where('employee_id', $f['employee']->id)->where('leave_year', 2027)->where('entry_type', 'carried_forward')->sum('quantity'))->toBe(7.0)
        ->and((float) LeaveBalanceLedgerEntry::query()->where('employee_id', $f['employee']->id)->where('leave_year', 2026)->where('entry_type', 'expired')->sum('quantity'))->toBe(-3.0);
});

it('denies carry forward preview and commit without manage capability', function (string $action): void {
    $f = leaveActionFixture();
    leaveActionOpening($f);
    $this->actingAs(User::factory()->create(['company_id' => $f['user']->company_id]));
    expect(fn () => Livewire::test(Index::class)->call($action))->toThrow(AuthorizationDeniedException::class);
    expect(LeaveBalanceLedgerEntry::query()->count())->toBe(1);
})->with(['previewCarryForward', 'commitCarryForward']);

it('requires a carry forward preview before committing', function (): void {
    leaveActionFixture();
    Livewire::test(Index::class)->call('commitCarryForward')
        ->assertDispatched('notify', message: 'Generate a preview first.', variant: 'error');
    expect(LeaveBalanceLedgerEntry::query()->count())->toBe(0);
});

it('selects only in-company leave details for display', function (): void {
    $f = leaveActionFixture();
    $request = leaveActionRequest($f);
    Livewire::test(Index::class)->call('selectRequest', $request->id)
        ->assertViewHas('selectedRequest', fn ($selected) => $selected?->id === $request->id);
    $company = Company::factory()->create(['tenant_id' => $f['employee']->company->tenant_id]);
    $request->update(['company_id' => $company->id]);
    Livewire::test(Index::class)->call('selectRequest', $request->id)
        ->assertViewHas('selectedRequest', fn ($selected) => $selected === null);
});

it('keeps leave navigation inside its surface and resets selection on an allowed tab', function (): void {
    leaveActionFixture();
    Livewire::test(Index::class)->set('selectedRequestId', 123)->call('setTab', 'approvals')
        ->assertSet('tab', 'apply')->assertSet('selectedRequestId', 123)
        ->call('setTab', 'calendar')->assertSet('tab', 'calendar')->assertSet('selectedRequestId', null);
});
