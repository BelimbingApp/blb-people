<?php

use App\Base\Tenancy\Contracts\TenantContext;
use App\Core\Company\Models\Company;
use App\Core\Employee\Models\Employee;
use App\Core\User\Models\User;
use App\Domains\People\Attendance\Livewire\Rosters;
use App\Domains\People\Attendance\Models\AttendancePolicyGroup;
use App\Domains\People\Attendance\Models\AttendanceRosterAssignment;
use App\Domains\People\Attendance\Models\AttendanceShiftTemplate;
use Livewire\Livewire;

/**
 * Sweep of request-supplied subject ids (#252): the roster page takes
 * employee ids as client-settable properties and action arguments. Each is
 * pinned to the acting user's company before it reaches a query.
 */
afterEach(function (): void {
    app(TenantContext::class)->clear();
});

beforeEach(function (): void {
    $this->withoutVite();
});

/** @return array{user: User, company: Company, sibling: Company, own: Employee, foreignA: Employee, foreignB: Employee} */
function rosterScopeFixture(): array
{
    $user = createAdminUser();
    $company = Company::query()->findOrFail($user->company_id);
    $sibling = Company::factory()->create(['tenant_id' => $user->tenant_id, 'name' => 'Sibling Roster Scope Co', 'status' => 'active']);
    $make = fn (Company $c, string $name): Employee => Employee::factory()->create(['company_id' => $c->id, 'short_name' => null, 'full_name' => $name, 'status' => 'active', 'employee_type' => 'full_time']);
    $own = $make($company, 'Own Roster Employee');
    $foreignA = $make($sibling, 'Foreign Roster A');
    $foreignB = $make($sibling, 'Foreign Roster B');

    // The sibling company has a published roster for its own people, so a
    // lookup that forgot the company axis would find real assignments.
    $shift = AttendanceShiftTemplate::query()->create([
        'company_id' => $sibling->id, 'code' => 'SIB', 'name' => 'Sibling shift',
        'starts_at' => '08:00:00', 'ends_at' => '17:00:00', 'expected_work_minutes' => 480,
        'effective_from' => '2026-01-01', 'status' => 'active',
    ]);
    $policy = AttendancePolicyGroup::query()->create([
        'company_id' => $sibling->id, 'code' => 'SIB', 'name' => 'Sibling policy',
        'effective_from' => '2026-01-01', 'status' => AttendancePolicyGroup::STATUS_ACTIVE,
    ]);
    foreach ([$foreignA, $foreignB] as $employee) {
        AttendanceRosterAssignment::query()->create([
            'company_id' => $sibling->id, 'employee_id' => $employee->id,
            'attendance_shift_template_id' => $shift->id, 'attendance_policy_group_id' => $policy->id,
            'effective_from' => '2026-09-01', 'effective_to' => '2026-09-01',
            'publish_state' => 'published', 'lock_state' => 'open', 'revision' => 1, 'exceptions' => [], 'metadata' => [],
        ]);
    }

    return compact('user', 'company', 'sibling', 'own', 'foreignA', 'foreignB');
}

test('a roster filter naming a sibling company employee never lists that employee', function (): void {
    $f = rosterScopeFixture();

    // An id outside the company resolves to no filter rows; the grid falls
    // back to the company's own people and the sibling's never appears.
    $page = Livewire::actingAs($f['user'])->test(Rosters::class)->set('rosterEmployeeId', (string) $f['foreignA']->id);
    $ids = $page->viewData('employees')->getCollection()->pluck('id')->all();

    expect($ids)->not->toContain($f['foreignA']->id)
        ->and($ids)->not->toContain($f['foreignB']->id)
        ->and($page->viewData('employees')->getCollection()->pluck('full_name')->all())->not->toContain('Foreign Roster A');
});

test('cell history for a sibling company employee does not open', function (): void {
    $f = rosterScopeFixture();

    Livewire::actingAs($f['user'])->test(Rosters::class)
        ->call('loadCellHistory', $f['foreignA']->id, '2026-09-01')
        ->assertSet('cellHistoryOpen', false)
        ->assertSet('cellHistoryEmployeeId', 0)
        ->assertDontSee('Foreign Roster A');
});

test('a swap between sibling company employees finds no assignment and writes nothing', function (): void {
    $f = rosterScopeFixture();
    $before = AttendanceRosterAssignment::query()->where('company_id', $f['sibling']->id)->get()->map(fn ($a) => $a->exceptions)->all();

    Livewire::actingAs($f['user'])->test(Rosters::class)
        ->set('swapFromEmployeeId', (string) $f['foreignA']->id)
        ->set('swapToEmployeeId', (string) $f['foreignB']->id)
        ->set('swapDate', '2026-09-01')
        ->call('swapRosterCells')
        ->assertHasErrors(['swapDate' => 'Both employees need an assignment on the selected date before swapping.'])
        ->assertNotDispatched('close-swap-modal');

    expect(AttendanceRosterAssignment::query()->where('company_id', $f['sibling']->id)->get()->map(fn ($a) => $a->exceptions)->all())->toBe($before);
});
