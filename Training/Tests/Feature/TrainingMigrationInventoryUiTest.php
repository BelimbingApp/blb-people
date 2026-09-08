<?php

use App\Base\Authz\Enums\PrincipalType;
use App\Base\Authz\Models\PrincipalRole;
use App\Base\Authz\Models\Role;
use App\Base\Tenancy\Contracts\TenantContext;
use App\Core\Company\Models\Company;
use App\Core\Employee\Models\Employee;
use App\Core\User\Models\User;
use App\Domains\People\Training\Data\TrainingMigrationSourceDraft;
use App\Domains\People\Training\Enums\MigrationSourceKind;
use App\Domains\People\Training\Livewire\Migration\Index;
use App\Domains\People\Training\Services\TrainingMigrationSourceStore;
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
    $owner = Employee::factory()->create(['company_id' => $company->id, 'full_name' => 'Mira Source Owner', 'short_name' => null, 'status' => 'active']);
    $foreignCompany = Company::factory()->create(['tenant_id' => $tenant->id]);
    $foreign = Employee::factory()->create(['company_id' => $foreignCompany->id, 'full_name' => 'Foreign Owner Hidden', 'short_name' => null, 'status' => 'active']);
    Livewire::actingAs($user)->test(Index::class)
        ->assertDontSee('Owner (employee id)')
        ->assertDontSee('Estimated volume (records)')
        ->call('createSource')
        ->assertSee('Mira Source Owner')
        ->assertDontSee('Foreign Owner Hidden')
        ->assertSee('Recording a source describes its metadata. It does not upload or import records.')
        ->set('sourceKey', 'register')->set('name', 'Legacy register')->set('format', 'csv')
        ->set('ownerEmployeeEntityId', (string) $foreign->id)
        ->call('save')->assertHasErrors('form')
        ->set('ownerEmployeeEntityId', (string) $owner->id)
        ->call('save')->assertHasNoErrors()->assertSet('showForm', false)
        ->assertSee('Legacy register')->assertSee('Mira Source Owner')
        ->set('search', 'no-match')->assertSee('No sources match your search.');

    for ($number = 1; $number <= 16; $number++) {
        app(TrainingMigrationSourceStore::class)->record($user, (int) $company->id,
            new TrainingMigrationSourceDraft(
                sourceKey: 'source-'.$number, name: sprintf('Source %02d', $number),
                kind: MigrationSourceKind::Workbook, format: 'csv',
            ));
    }
    Livewire::actingAs($user)->test(Index::class)
        ->assertSee('Source 01')->assertDontSee('Source 16')
        ->call('gotoPage', 2)->assertSee('Source 16')->assertDontSee('Legacy register')
        ->set('search', 'Mira')->assertSee('Legacy register')->assertDontSee('Source 16')
        ->set('search', '')->call('sortSources')
        ->assertSeeInOrder(['Source 16', 'Source 15'])->assertDontSee('Legacy register');
});
