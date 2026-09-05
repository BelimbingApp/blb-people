<?php

use App\Base\Authz\Exceptions\AuthorizationDeniedException;
use App\Base\Tenancy\Contracts\TenantContext;
use App\Core\Employee\Models\Employee;
use App\Core\User\Models\User;
use App\Domains\People\Employees\Livewire\Index;
use App\Domains\People\Employees\Livewire\Show;
use App\Domains\People\Settings\Models\EmployeePortalAccess;
use App\Domains\People\Settings\Models\EmployeeProfileChangeRequest;
use App\Domains\People\Settings\Models\PeopleSavedEmployeeView;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Livewire\Livewire;

afterEach(fn () => app(TenantContext::class)->clear());

function employeeActionFixture(): array
{
    $user = createAdminUser();
    test()->actingAs($user);
    $employee = Employee::factory()->create([
        'company_id' => $user->company_id, 'full_name' => 'Original Employee',
        'employee_number' => 'ACTION-001', 'status' => 'active',
    ]);
    $access = EmployeePortalAccess::query()->create([
        'employee_id' => $employee->id, 'display_name' => 'Original Employee', 'status' => 'active',
    ]);
    $request = EmployeeProfileChangeRequest::query()->create([
        'employee_id' => $employee->id, 'requested_by_user_id' => $user->id,
        'request_type' => 'profile_update', 'status' => 'submitted',
        'requested_changes' => ['full_name' => 'Requested Name'], 'submitted_at' => now(),
    ]);

    return compact('user', 'employee', 'access', 'request');
}

it('saves employee details to the selected employee with success feedback', function (): void {
    $f = employeeActionFixture();
    Livewire::test(Show::class, ['employee' => $f['employee']])
        ->set('fullName', 'Approved Name')->set('email', 'approved@example.test')
        ->call('saveEmployeeDetails')->assertHasNoErrors()->assertDispatched('notify', variant: 'success');
    expect($f['employee']->fresh()->full_name)->toBe('Approved Name')
        ->and($f['employee']->fresh()->email)->toBe('approved@example.test');
});

it('validates employee detail input without persisting a partial update', function (): void {
    $f = employeeActionFixture();
    Livewire::test(Show::class, ['employee' => $f['employee']])
        ->set('fullName', 'Attempted Name')->set('email', 'invalid-email')
        ->call('saveEmployeeDetails')->assertHasErrors(['email' => 'email']);
    expect($f['employee']->fresh()->full_name)->toBe('Original Employee');
});

it('revokes the selected employees access', function (): void {
    $f = employeeActionFixture();
    Livewire::test(Show::class, ['employee' => $f['employee']])->call('revokeAccess')
        ->assertDispatched('notify', variant: 'success');
    expect($f['access']->fresh()->status)->toBe('revoked');
});

it('rejects the selected employees request without applying proposed changes', function (): void {
    $f = employeeActionFixture();
    Livewire::test(Show::class, ['employee' => $f['employee']])
        ->set('requestReviewNotes.'.$f['request']->id, 'Evidence incomplete')
        ->call('rejectRequest', $f['request']->id)->assertDispatched('notify', variant: 'success');
    expect($f['request']->fresh()->status)->toBe('rejected')
        ->and($f['request']->fresh()->reviewed_by_user_id)->toBe($f['user']->id)
        ->and($f['request']->fresh()->reviewed_at)->not->toBeNull()
        ->and($f['employee']->fresh()->full_name)->toBe('Original Employee');
});

it('denies each previously untested employee write without its capability', function (string $action): void {
    $f = employeeActionFixture();
    $this->actingAs(User::factory()->create(['company_id' => $f['user']->company_id]));
    expect(fn () => Livewire::test(Show::class, ['employee' => $f['employee']])
        ->set('fullName', 'Forbidden Name')->call($action, ...($action === 'rejectRequest' ? [$f['request']->id] : [])))
        ->toThrow(AuthorizationDeniedException::class);
    expect($f['employee']->fresh()->full_name)->toBe('Original Employee')
        ->and($f['access']->fresh()->status)->toBe('active')
        ->and($f['request']->fresh()->status)->toBe('submitted');
})->with(['saveEmployeeDetails', 'revokeAccess', 'rejectRequest']);

it('refuses rejecting another employees request even for an authorized reviewer', function (): void {
    $f = employeeActionFixture();
    $other = Employee::factory()->create(['company_id' => $f['user']->company_id]);
    $f['request']->update(['employee_id' => $other->id]);
    expect(fn () => Livewire::test(Show::class, ['employee' => $f['employee']])->call('rejectRequest', $f['request']->id))
        ->toThrow(ModelNotFoundException::class);
    expect($f['request']->fresh()->status)->toBe('submitted');
});

it('cancels draft filters without changing the applied employee filter', function (): void {
    employeeActionFixture();
    Livewire::test(Index::class)->set('payRateType', 'monthly')->call('openFilterDrawer')
        ->set('draftPayRateType', 'hourly')->call('closeFilterDrawer')
        ->assertSet('filterDrawerOpen', false)->assertSet('payRateType', 'monthly')
        ->assertSet('draftPayRateType', 'monthly');
});

it('clears advanced filters while preserving the quick employee search', function (): void {
    employeeActionFixture();
    Livewire::test(Index::class)->set('search', 'Original')->set('payRateType', 'monthly')
        ->set('draftPayRateType', 'hourly')->call('clearAdvancedFilters')
        ->assertSet('search', 'Original')->assertSet('payRateType', '')->assertSet('draftPayRateType', '');
});

it('cancels saving a view and clears draft sharing information without creating it', function (): void {
    employeeActionFixture();
    Livewire::test(Index::class)->call('openSaveViewModal')
        ->set('savedViewName', 'Do not save')->set('savedViewVisibility', 'company')
        ->call('closeSaveViewModal')->assertSet('saveViewModalOpen', false)
        ->assertSet('savedViewName', '')->assertSet('savedViewVisibility', 'private');
    expect(PeopleSavedEmployeeView::query()->count())->toBe(0);
});

it('sorts employee rows by an allowed column and ignores an unknown column', function (): void {
    $f = employeeActionFixture();
    $other = Employee::factory()->create(['company_id' => $f['user']->company_id, 'full_name' => 'Zulu Employee']);
    Livewire::test(Index::class)->call('sort', 'full_name')
        ->assertSet('sortDir', 'desc')
        ->assertViewHas('employees', fn ($rows) => $rows->pluck('id')->all() === [$other->id, $f['employee']->id])
        ->call('sort', 'salary_secret')->assertSet('sortBy', 'full_name')->assertSet('sortDir', 'desc');
});
