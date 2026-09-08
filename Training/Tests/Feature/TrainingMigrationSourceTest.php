<?php

use App\Base\Authz\Enums\PrincipalType;
use App\Base\Authz\Exceptions\AuthorizationDeniedException;
use App\Base\Authz\Models\PrincipalRole;
use App\Base\Authz\Models\Role;
use App\Base\Tenancy\Contracts\TenantContext;
use App\Core\Company\Models\Company;
use App\Core\Employee\Models\Employee;
use App\Core\User\Models\User;
use App\Domains\People\Training\Data\TrainingMigrationSourceDraft;
use App\Domains\People\Training\Enums\MigrationSourceKind;
use App\Domains\People\Training\Exceptions\InvalidTrainingMigrationSourceException;
use App\Domains\People\Training\Livewire\Migration\Index;
use App\Domains\People\Training\Models\TrainingMigrationSource;
use App\Domains\People\Training\Models\TrainingMigrationSourceSignoff;
use App\Domains\People\Training\Services\TrainingMigrationSourceStore;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

/**
 * 0015-a: the migration source inventory HR records and signs before any
 * production import. Self-contained: helpers are prefixed migSrc and live here.
 */
afterEach(function (): void {
    app(TenantContext::class)->clear();
});

beforeEach(function (): void {
    $this->withoutVite();
});

function migSrcUser(Company $company, string $roleCode, string $name): User
{
    $user = User::factory()->create(['company_id' => $company->id, 'name' => $name]);
    PrincipalRole::query()->create([
        'company_id' => $company->id, 'principal_type' => PrincipalType::USER->value, 'principal_id' => $user->id,
        'role_id' => Role::query()->whereNull('company_id')->where('code', $roleCode)->sole()->id,
    ]);

    return $user;
}

/** One company side: HR (records and signs), HOD (reads), an employee (neither), and an owner employee. */
function migSrcSide(int $tenantId, Company $company, string $label): array
{
    $hr = migSrcUser($company, 'people_hr', $label.' HR');
    $hod = migSrcUser($company, 'people_hod', $label.' HOD');
    $staff = migSrcUser($company, 'people_employee', $label.' Staff');
    $owner = Employee::factory()->create(['company_id' => $company->id, 'full_name' => $label.' Owner', 'short_name' => null, 'supervisor_id' => null, 'status' => 'active', 'employee_type' => 'full_time']);

    return compact('hr', 'hod', 'staff', 'owner') + ['company' => $company, 'companyId' => (int) $company->id, 'tenantId' => $tenantId];
}

/** @return array{tenantId: int, alpha: array, beta: array} */
function migSrcFixture(string $label = 'MigSrc'): array
{
    $tenant = createTenant(['name' => $label.' Tenant']);
    $tenantId = (int) $tenant->id;
    app(TenantContext::class)->set($tenantId);
    setupAuthzRoles();
    $alpha = Company::factory()->create(['tenant_id' => $tenantId, 'name' => $label.' Alpha', 'status' => 'active']);
    $beta = Company::factory()->create(['tenant_id' => $tenantId, 'name' => $label.' Beta', 'status' => 'active']);

    return ['tenantId' => $tenantId, 'alpha' => migSrcSide($tenantId, $alpha, $label.' A'), 'beta' => migSrcSide($tenantId, $beta, $label.' B')];
}

function migSrcDraft(array $s, string $key = 'legacy-portal', array $overrides = []): TrainingMigrationSourceDraft
{
    return new TrainingMigrationSourceDraft(...array_replace([
        'sourceKey' => $key, 'name' => 'Legacy skills portal', 'kind' => MigrationSourceKind::Portal, 'format' => 'csv export',
        'ownerEmployeeEntityId' => (int) $s['owner']->id, 'estimatedVolume' => 1200,
        'retentionNote' => 'Kept 7 years after cutover', 'dataQualityNote' => 'Certificate dates are free text',
    ], $overrides));
}

function migSrcRecord(array $s, string $key = 'legacy-portal'): TrainingMigrationSource
{
    return app(TrainingMigrationSourceStore::class)->record($s['hr'], $s['companyId'], migSrcDraft($s, $key));
}

function migSrcCount(array $s): int
{
    return TrainingMigrationSource::query()->forCompany($s['tenantId'], $s['companyId'])->count();
}

function migSrcSignoffs(array $s, TrainingMigrationSource $source): int
{
    return TrainingMigrationSourceSignoff::query()->forCompany($s['tenantId'], $s['companyId'])->where('training_migration_source_id', $source->id)->count();
}

test('HR records a source carrying the tenant, company and acting user; a HOD without manage is refused and the count is unchanged', function (): void {
    $f = migSrcFixture();
    $a = $f['alpha'];

    $source = migSrcRecord($a);

    expect((int) $source->tenant_id)->toBe($f['tenantId'])
        ->and((int) $source->company_entity_id)->toBe($a['companyId'])
        ->and($source->created_by_user_id)->toBe((int) $a['hr']->id)
        ->and($source->kind)->toBe(MigrationSourceKind::Portal)
        ->and($source->owner_employee_id)->toBe((int) $a['owner']->id)
        ->and($source->estimated_volume)->toBe(1200);

    $before = migSrcCount($a);
    expect(fn () => app(TrainingMigrationSourceStore::class)->record($a['hod'], $a['companyId'], migSrcDraft($a, 'hod-attempt')))
        ->toThrow(AuthorizationDeniedException::class);
    expect(migSrcCount($a))->toBe($before);
});

test('an owner outside the company, a bad key and a duplicate key are refused', function (): void {
    $f = migSrcFixture();
    $a = $f['alpha'];
    $b = $f['beta'];
    migSrcRecord($a);
    $store = app(TrainingMigrationSourceStore::class);

    expect(fn () => $store->record($a['hr'], $a['companyId'], migSrcDraft($a, 'foreign-owner', ['ownerEmployeeEntityId' => (int) $b['owner']->id])))
        ->toThrow(InvalidTrainingMigrationSourceException::class, 'owner must be an employee of this company');
    expect(fn () => $store->record($a['hr'], $a['companyId'], migSrcDraft($a, 'Bad Key!')))
        ->toThrow(InvalidTrainingMigrationSourceException::class, 'source key');
    expect(fn () => $store->record($a['hr'], $a['companyId'], migSrcDraft($a, 'legacy-portal')))
        ->toThrow(InvalidTrainingMigrationSourceException::class, 'already exists');
    expect(migSrcCount($a))->toBe(1);
});

test('updating a source after it is signed is refused and the stored fields are byte-identical', function (): void {
    $f = migSrcFixture();
    $a = $f['alpha'];
    $store = app(TrainingMigrationSourceStore::class);
    $source = migSrcRecord($a);

    $updated = $store->update($a['hr'], $a['companyId'], (int) $source->id, migSrcDraft($a, 'legacy-portal', ['name' => 'Renamed portal', 'estimatedVolume' => 1300]));
    expect($updated->name)->toBe('Renamed portal')->and($updated->estimated_volume)->toBe(1300);

    $store->sign($a['hr'], $a['companyId'], (int) $source->id, 'Inventoried.');
    $before = $source->fresh()->getAttributes();

    expect(fn () => $store->update($a['hr'], $a['companyId'], (int) $source->id, migSrcDraft($a, 'legacy-portal', ['name' => 'After signing'])))
        ->toThrow(InvalidTrainingMigrationSourceException::class, 'signed migration source cannot be changed');
    expect($source->fresh()->getAttributes())->toBe($before);
});

test('signing appends one sign-off row; signing again is refused and the count stays one; the row is append-only', function (): void {
    $f = migSrcFixture();
    $a = $f['alpha'];
    $store = app(TrainingMigrationSourceStore::class);
    $source = migSrcRecord($a);

    $signoff = $store->sign($a['hr'], $a['companyId'], (int) $source->id, 'Inventoried.');
    expect($signoff->signed_by_user_id)->toBe((int) $a['hr']->id)
        ->and($signoff->note)->toBe('Inventoried.')
        ->and(migSrcSignoffs($a, $source))->toBe(1);

    expect(fn () => $store->sign($a['hr'], $a['companyId'], (int) $source->id))
        ->toThrow(InvalidTrainingMigrationSourceException::class, 'already signed');
    expect(migSrcSignoffs($a, $source))->toBe(1);

    expect(fn () => $signoff->update(['note' => 'Rewritten']))->toThrow(InvalidTrainingMigrationSourceException::class, 'cannot be modified');
    expect(fn () => $signoff->delete())->toThrow(InvalidTrainingMigrationSourceException::class, 'cannot be deleted');
    expect(fn () => $store->sign($a['hod'], $a['companyId'], (int) $source->id))->toThrow(AuthorizationDeniedException::class);
});

test('signedInventory() is false with an unsigned source, true once every source of the company is signed, and a sibling company does not count', function (): void {
    $f = migSrcFixture();
    $a = $f['alpha'];
    $b = $f['beta'];
    $store = app(TrainingMigrationSourceStore::class);

    expect($store->signedInventory($a['companyId']))->toBeFalse();

    $first = migSrcRecord($a, 'legacy-portal');
    $second = migSrcRecord($a, 'workbook');
    $theirs = migSrcRecord($b, 'beta-portal');
    $store->sign($b['hr'], $b['companyId'], (int) $theirs->id);

    expect($store->signedInventory($a['companyId']))->toBeFalse()
        ->and($store->signedInventory($b['companyId']))->toBeTrue();

    $store->sign($a['hr'], $a['companyId'], (int) $first->id);
    expect($store->signedInventory($a['companyId']))->toBeFalse();

    $store->sign($a['hr'], $a['companyId'], (int) $second->id);
    expect($store->signedInventory($a['companyId']))->toBeTrue();
});

test('a sibling tenant\'s sources are never listed, counted or signable from the acting tenant', function (): void {
    $f = migSrcFixture();
    $g = migSrcFixture('Away');
    $away = migSrcRecord($g['alpha'], 'away-portal');

    app(TenantContext::class)->set($f['tenantId']);
    $a = $f['alpha'];
    $store = app(TrainingMigrationSourceStore::class);
    $mine = migSrcRecord($a);

    expect($store->inventory($a['hr'], $a['companyId'])->pluck('id')->all())->toBe([(int) $mine->id])
        ->and($store->inventory($a['hr'], $a['companyId'])->pluck('id')->all())->not->toContain((int) $away->id)
        ->and(fn () => $store->sign($a['hr'], $a['companyId'], (int) $away->id))->toThrow(InvalidTrainingMigrationSourceException::class, 'not found');
    expect(TrainingMigrationSourceSignoff::query()->forCompany($g['tenantId'], $g['alpha']['companyId'])->count())->toBe(0);
});

test('the page renders the captioned inventory for HR and HOD and refuses a user with neither capability', function (): void {
    $f = migSrcFixture();
    $a = $f['alpha'];
    $source = migSrcRecord($a);
    app(TrainingMigrationSourceStore::class)->sign($a['hr'], $a['companyId'], (int) $source->id, 'Inventoried.');

    $this->actingAs($a['hr'])->get(route('people.training.migration.index'))
        ->assertOk()
        ->assertSee('Migration source inventory')
        ->assertSee('legacy-portal')
        ->assertSee('every recorded source carries a sign-off');
    $this->actingAs($a['hod'])->get(route('people.training.migration.index'))
        ->assertOk()
        ->assertSee('Migration source inventory')
        ->assertDontSee('Record a source');
    $this->actingAs($a['staff'])->get(route('people.training.migration.index'))->assertForbidden();
});

test('the page records, edits and signs through the store, and a HOD is refused the actions', function (): void {
    $f = migSrcFixture();
    $a = $f['alpha'];

    Livewire::actingAs($a['hr'])->test(Index::class)
        ->call('selectCompany', $a['companyId'])
        ->set('sourceKey', 'workbook')->set('name', 'SBTG workbook')->set('kind', 'workbook')->set('format', 'xlsx')
        ->set('ownerEmployeeEntityId', (string) $a['owner']->id)->set('estimatedVolume', '350')
        ->call('save')
        ->assertHasNoErrors();
    $source = TrainingMigrationSource::query()->forCompany($f['tenantId'], $a['companyId'])->where('source_key', 'workbook')->sole();
    expect($source->kind)->toBe(MigrationSourceKind::Workbook)->and($source->estimated_volume)->toBe(350);

    Livewire::actingAs($a['hr'])->test(Index::class)
        ->call('edit', (int) $source->id)
        ->assertSet('editingId', (int) $source->id)
        ->assertSet('name', 'SBTG workbook')
        ->set('name', 'SBTG skills workbook')
        ->call('save')
        ->assertHasNoErrors()
        ->call('cancelEdit')
        ->assertSet('editingId', null);
    expect($source->fresh()->name)->toBe('SBTG skills workbook');

    Livewire::actingAs($a['hr'])->test(Index::class)
        ->set('signNote.'.$source->id, 'Checked with the owner.')
        ->call('sign', (int) $source->id)
        ->assertHasNoErrors();
    expect(migSrcSignoffs($a, $source))->toBe(1);

    Livewire::actingAs($a['hr'])->test(Index::class)
        ->call('sign', (int) $source->id)
        ->assertHasErrors(['sign.'.$source->id]);
    expect(migSrcSignoffs($a, $source))->toBe(1);

    Livewire::actingAs($a['hod'])->test(Index::class)
        ->set('sourceKey', 'hod-attempt')->set('name', 'HOD attempt')->set('format', 'paper')
        ->call('save')
        ->assertForbidden();
    expect(migSrcCount($a))->toBe(1);
});

test('HR of company A can neither sign nor update a sibling company\'s source, and its counts are unchanged', function (): void {
    $f = migSrcFixture();
    $a = $f['alpha'];
    $b = $f['beta'];
    $theirs = migSrcRecord($b, 'beta-portal');
    $store = app(TrainingMigrationSourceStore::class);
    $before = $theirs->fresh()->getAttributes();

    expect(fn () => $store->sign($a['hr'], $a['companyId'], (int) $theirs->id))
        ->toThrow(InvalidTrainingMigrationSourceException::class, 'not found');
    expect(fn () => $store->update($a['hr'], $a['companyId'], (int) $theirs->id, migSrcDraft($a, 'beta-portal', ['name' => 'Taken over'])))
        ->toThrow(InvalidTrainingMigrationSourceException::class, 'not found');
    expect(migSrcSignoffs($b, $theirs))->toBe(0)
        ->and($theirs->fresh()->getAttributes())->toBe($before);
});

test('a sign-off survives the query builder, not only the model', function (): void {
    // #449: Eloquent does not fire updating/deleting for builder-level writes,
    // so the model guard alone left a mass update or delete free to rewrite or
    // remove a signature. Each attempt runs in its own transaction: on
    // PostgreSQL a raised exception poisons the surrounding one, so a shared
    // transaction would report 25P02 for the second attempt instead of the
    // trigger's own message.
    $f = migSrcFixture('MigSrcBuilder');
    $a = $f['alpha'];
    $source = migSrcRecord($a);
    $signoff = app(TrainingMigrationSourceStore::class)
        ->sign($a['hr'], $a['companyId'], (int) $source->id, 'Inventoried.');

    $table = 'people_training_migration_source_signoffs';

    expect(fn () => DB::transaction(fn () => DB::table($table)->where('id', $signoff->id)->update(['note' => 'Rewritten by mass update'])))
        ->toThrow(QueryException::class);
    expect(fn () => DB::transaction(fn () => DB::table($table)->where('id', $signoff->id)->delete()))
        ->toThrow(QueryException::class);

    // Through the model's own builder too, which is the shape the issue names.
    expect(fn () => DB::transaction(fn () => TrainingMigrationSourceSignoff::query()
        ->forCompany($a['tenantId'], $a['companyId'])->update(['note' => 'Rewritten'])))
        ->toThrow(QueryException::class);
    expect(fn () => DB::transaction(fn () => TrainingMigrationSourceSignoff::query()
        ->forCompany($a['tenantId'], $a['companyId'])->delete()))
        ->toThrow(QueryException::class);

    $row = DB::table($table)->where('id', $signoff->id)->first();
    expect($row)->not->toBeNull()
        ->and($row->note)->toBe('Inventoried.');
});
