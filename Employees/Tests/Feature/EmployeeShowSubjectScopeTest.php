<?php

use App\Base\Tenancy\Contracts\TenantContext;
use App\Core\Company\Models\Company;
use App\Core\Employee\Models\Employee;
use App\Domains\People\Employees\Livewire\Show;
use Livewire\Livewire;

/**
 * Sweep of request-supplied subject ids (#252): the employee page binds its
 * subject from the route, so the company-tree check in mount() is the guard.
 */
afterEach(function (): void {
    app(TenantContext::class)->clear();
});

test('the employee page refuses a route-bound employee of a sibling company', function (): void {
    $user = createAdminUser();
    $sibling = Company::factory()->create(['tenant_id' => $user->tenant_id, 'name' => 'Sibling Show Co', 'status' => 'active']);
    $foreign = Employee::factory()->create(['company_id' => $sibling->id, 'short_name' => null, 'full_name' => 'Foreign Show Employee', 'status' => 'active', 'employee_type' => 'full_time']);
    $own = Employee::factory()->create(['company_id' => $user->company_id, 'short_name' => null, 'full_name' => 'Own Show Employee', 'status' => 'active', 'employee_type' => 'full_time']);

    Livewire::actingAs($user)->test(Show::class, ['employee' => $foreign])->assertNotFound();

    Livewire::actingAs($user)->test(Show::class, ['employee' => $own])->assertOk()->assertSee('Own Show Employee');
});
