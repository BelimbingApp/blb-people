<?php

use App\Base\Tenancy\Contracts\TenantContext;
use App\Core\Company\Models\Company;
use App\Core\Employee\Models\Employee;
use App\Domains\People\Leave\Data\LeaveLedgerEntryData;
use App\Domains\People\Leave\Data\LeaveLedgerEntrySource;
use App\Domains\People\Leave\Data\LeaveLedgerEntrySubject;
use App\Domains\People\Leave\Livewire\Index;
use App\Domains\People\Leave\Models\LeaveType;
use App\Domains\People\Leave\Services\LeaveBalanceLedgerService;
use Livewire\Livewire;

/**
 * Sweep of request-supplied subject ids (#252): the balance statement takes
 * an employee id as a client-settable property; the statement builder is
 * given the acting user's company, so a sibling's ledger never renders.
 */
afterEach(function (): void {
    app(TenantContext::class)->clear();
});

beforeEach(function (): void {
    $this->withoutVite();
});

test('a balance statement for a sibling company employee shows no ledger rows', function (): void {
    $user = createAdminUser();
    $sibling = Company::factory()->create(['tenant_id' => $user->tenant_id, 'name' => 'Sibling Leave Co', 'status' => 'active']);
    $foreign = Employee::factory()->create(['company_id' => $sibling->id, 'short_name' => null, 'full_name' => 'Foreign Leave Employee', 'status' => 'active', 'employee_type' => 'full_time']);
    $type = LeaveType::query()->create([
        'company_id' => $sibling->id, 'code' => 'sib', 'name' => 'Sibling leave',
        'paid' => true, 'default_unit' => 'day', 'default_approval_depth' => 1,
        'interacts_with_payroll' => false, 'status' => 'active',
    ]);
    app(LeaveBalanceLedgerService::class)->record(new LeaveLedgerEntryData(
        new LeaveLedgerEntrySubject((int) $sibling->id, (int) $foreign->id, (int) $type->id, 2026),
        'opening', 10, 'day', new LeaveLedgerEntrySource('manual_adjustment'),
    ));

    $page = Livewire::actingAs($user)->test(Index::class, ['surface' => 'settings', 'section' => 'balances'])
        ->set('balanceYear', 2026)
        ->set('balanceEmployeeId', (string) $foreign->id);

    $statement = $page->viewData('balanceStatement');

    expect($statement)->not->toBeNull()
        ->and($statement->rows)->toBe([]);

    $page->assertDontSee('Sibling leave');
});
