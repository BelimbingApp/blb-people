<?php

use App\Base\Authz\Enums\PrincipalType;
use App\Base\Authz\Models\PrincipalRole;
use App\Base\Authz\Models\Role;
use App\Base\Tenancy\Contracts\TenantContext;
use App\Core\Employee\Models\Employee;
use App\Core\User\Models\User;
use App\Domains\People\Training\Livewire\Migration\Index;
use Livewire\Livewire;

it('opens a separate source form with readable company-scoped owner choices', function (): void {
    $this->withoutVite();
    [$tenant, $company] = createTenantWithCompany();
    app(TenantContext::class)->set((int) $tenant->id);
    setupAuthzRoles();
    $user = User::factory()->create(['company_id' => $company->id]);
    PrincipalRole::query()->create([
        'company_id' => $company->id, 'principal_type' => PrincipalType::USER->value,
        'principal_id' => $user->id,
        'role_id' => Role::query()->whereNull('company_id')->where('code', 'people_hr')->sole()->id,
    ]);
    $owner = Employee::factory()->create(['company_id' => $company->id, 'full_name' => 'Mira Source Owner']);
    Livewire::actingAs($user)->test(Index::class)
        ->assertDontSee('Owner (employee id)')
        ->assertDontSee('Estimated volume (records)')
        ->call('createSource')
        ->assertSee('Mira Source Owner')
        ->assertSee('Recording a source describes its metadata. It does not upload or import records.')
        ->set('sourceKey', 'register')->set('name', 'Legacy register')->set('format', 'csv')
        ->set('ownerEmployeeEntityId', (string) $owner->id)
        ->call('save')->assertHasNoErrors()->assertSet('showForm', false)
        ->assertSee('Legacy register')->assertSee('Mira Source Owner')
        ->set('search', 'no-match')->assertSee('No sources match your search.');
});
