<?php

use App\Base\Tenancy\Contracts\TenantContext;
use App\Core\Company\Models\Company;
use App\Core\Employee\Models\Employee;
use App\Core\User\Models\User;
use App\Domains\People\Attendance\Livewire\RosterEmployeeHistory;
use Livewire\Livewire;

/**
 * The roster history page takes an employee id from the request. It is a
 * workforce subject and resolves through the seam with the acting user's
 * company (#249): anything outside scope, or not a stable id, is refused.
 */
afterEach(function (): void {
    app(TenantContext::class)->clear();
});

beforeEach(function (): void {
    $this->withoutVite();
});

/** @return array{user: User, own: Employee, foreign: Employee, inactive: Employee} */
function rosterHistorySubjectFixture(): array
{
    $user = createAdminUser();
    $companyId = (int) $user->company_id;
    $own = Employee::factory()->create(['company_id' => $companyId, 'full_name' => 'Own Operator', 'short_name' => null, 'status' => 'active', 'employee_type' => 'full_time']);
    $inactive = Employee::factory()->create(['company_id' => $companyId, 'full_name' => 'Former Operator', 'short_name' => null, 'status' => 'inactive', 'employee_type' => 'full_time']);
    $sibling = Company::factory()->create(['tenant_id' => $user->tenant_id, 'name' => 'Sibling Roster Co', 'status' => 'active']);
    $foreign = Employee::factory()->create(['company_id' => $sibling->id, 'full_name' => 'Foreign Operator', 'short_name' => null, 'status' => 'active', 'employee_type' => 'full_time']);

    return ['user' => $user, 'own' => $own, 'foreign' => $foreign, 'inactive' => $inactive];
}

test('an employee of the acting user\'s company resolves through the seam and is shown', function (): void {
    $f = rosterHistorySubjectFixture();

    Livewire::actingAs($f['user'])
        ->test(RosterEmployeeHistory::class, ['employeeId' => (string) $f['own']->id])
        ->assertOk()
        ->assertViewHas('refused', false)
        ->assertSee('Own Operator')
        ->assertDontSee('not in your company scope');
});

test('a foreign employee_id is refused, not looked up', function (): void {
    $f = rosterHistorySubjectFixture();

    Livewire::actingAs($f['user'])
        ->test(RosterEmployeeHistory::class, ['employeeId' => (string) $f['foreign']->id])
        ->assertOk()
        ->assertViewHas('employee', null)
        ->assertViewHas('refused', true)
        ->assertSee('not in your company scope')
        ->assertDontSee('Foreign Operator');
});

test('a request value that is not a stable id is refused rather than coerced to a coincident id', function (string $crafted): void {
    $f = rosterHistorySubjectFixture();

    // Before the seam, (int) '7abc' silently became employee 7.
    Livewire::actingAs($f['user'])
        ->test(RosterEmployeeHistory::class, ['employeeId' => str_replace('{id}', (string) $f['own']->id, $crafted)])
        ->assertOk()
        ->assertViewHas('employee', null)
        ->assertViewHas('refused', true)
        ->assertDontSee('Own Operator');
})->with(['{id}abc', ' {id}', '{id} OR 1=1', '0x{id}']);

test('a deactivated employee is refused by the seam', function (): void {
    $f = rosterHistorySubjectFixture();

    Livewire::actingAs($f['user'])
        ->test(RosterEmployeeHistory::class, ['employeeId' => (string) $f['inactive']->id])
        ->assertOk()
        ->assertViewHas('employee', null)
        ->assertViewHas('refused', true)
        ->assertSee('That employee is no longer active.')
        ->assertDontSee('not in your company scope')
        ->assertDontSee('Former Operator');
});

test('an employee_id in the query string still goes through the seam: a foreign one is refused', function (): void {
    $f = rosterHistorySubjectFixture();

    $this->actingAs($f['user'])
        ->get(route('people.attendance.roster.employee-history', ['employee_id' => $f['foreign']->id]))
        ->assertOk()
        ->assertSee('not in your company scope')
        ->assertDontSee('Foreign Operator');
});

test('an employee_id in the query string still goes through the seam: an own one is shown', function (): void {
    $user = createAdminUser();
    $own = Employee::factory()->create(['company_id' => $user->company_id, 'full_name' => 'Own Operator', 'short_name' => null, 'status' => 'active', 'employee_type' => 'full_time']);

    $this->actingAs($user)
        ->get(route('people.attendance.roster.employee-history', ['employee_id' => $own->id]))
        ->assertOk()
        ->assertSee('Own Operator');
});
