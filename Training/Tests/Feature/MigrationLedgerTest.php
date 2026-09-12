<?php

use App\Base\Authz\Enums\PrincipalType;
use App\Base\Authz\Models\PrincipalRole;
use App\Base\Authz\Models\Role;
use App\Base\Tenancy\Contracts\TenantContext;
use App\Core\Company\Models\Company;
use App\Core\Employee\Models\Employee;
use App\Core\User\Models\User;
use App\Domains\People\Settings\Models\EmployeeWorkProfile;
use App\Domains\People\Settings\Models\PeopleReferenceEntry;
use App\Domains\People\Skills\Models\RequirementProfile;
use App\Domains\People\Skills\Services\RequirementProfileStore;
use App\Domains\People\Skills\Services\StarterProfileImporter;
use App\Domains\People\Training\Enums\MigrationLedgerStatus;
use App\Domains\People\Training\Exceptions\InvalidMigrationLedgerException;
use App\Domains\People\Training\Livewire\Migration\Index;
use App\Domains\People\Training\Models\TrainingMigrationLedgerEntry;
use App\Domains\People\Training\Services\MigrationLedger;
use Illuminate\Support\Facades\Artisan;
use Livewire\Livewire;

/**
 * 0015-d / #400: migration ledger provenance, quarantine, and reconcile.
 * Self-contained: helpers are prefixed migLed and live here.
 */
afterEach(function (): void {
    app(TenantContext::class)->clear();
});

beforeEach(function (): void {
    $this->withoutVite();
});

function migLedUser(Company $company, string $roleCode, string $name): User
{
    $user = User::factory()->create(['company_id' => $company->id, 'name' => $name]);
    PrincipalRole::query()->create([
        'company_id' => $company->id, 'principal_type' => PrincipalType::USER->value, 'principal_id' => $user->id,
        'role_id' => Role::query()->whereNull('company_id')->where('code', $roleCode)->sole()->id,
    ]);

    return $user;
}

function migLedUnit(Company $company, string $code, string $name): void
{
    $unit = PeopleReferenceEntry::query()->create([
        'company_id' => $company->id, 'type' => PeopleReferenceEntry::TYPE_ORGANIZATION_UNIT,
        'code' => $code, 'name' => $name, 'status' => PeopleReferenceEntry::STATUS_ACTIVE,
    ]);
    $employee = Employee::factory()->create([
        'company_id' => $company->id, 'full_name' => $name.' One', 'short_name' => null,
        'supervisor_id' => null, 'status' => 'active', 'employee_type' => 'full_time',
    ]);
    EmployeeWorkProfile::query()->create(['employee_id' => $employee->id, 'organization_unit_id' => $unit->id]);
}

/** @return array{tenantId: int, alpha: array, beta: array} */
function migLedFixture(string $label = 'MigLed'): array
{
    $tenant = createTenant(['name' => $label.' Tenant']);
    $tenantId = (int) $tenant->id;
    app(TenantContext::class)->set($tenantId);
    setupAuthzRoles();
    $alpha = Company::factory()->create(['tenant_id' => $tenantId, 'name' => $label.' Alpha', 'status' => 'active']);
    $beta = Company::factory()->create(['tenant_id' => $tenantId, 'name' => $label.' Beta', 'status' => 'active']);
    migLedUnit($alpha, 'OPS', 'Operations');
    migLedUnit($beta, 'BOPS', 'Operations');

    return [
        'tenantId' => $tenantId,
        'alpha' => [
            'company' => $alpha, 'companyId' => (int) $alpha->id, 'tenantId' => $tenantId,
            'hr' => migLedUser($alpha, 'people_hr', $label.' A HR'),
            'staff' => migLedUser($alpha, 'people_employee', $label.' A Staff'),
        ],
        'beta' => [
            'company' => $beta, 'companyId' => (int) $beta->id, 'tenantId' => $tenantId,
            'hr' => migLedUser($beta, 'people_hr', $label.' B HR'),
        ],
    ];
}

function migLedCsv(array $rows): string
{
    $path = tempnam(sys_get_temp_dir(), 'migled');
    $lines = [implode(',', StarterProfileImporter::HEADER)];
    foreach ($rows as $row) {
        $lines[] = implode(',', $row);
    }
    file_put_contents($path, implode("\n", $lines)."\n");

    return $path;
}

function migLedCount(array $s, ?MigrationLedgerStatus $status = null): int
{
    $query = TrainingMigrationLedgerEntry::query()->forCompany($s['tenantId'], $s['companyId']);
    if ($status !== null) {
        $query->where('status', $status);
    }

    return $query->count();
}

function migLedStatusCounts(array $s): array
{
    $counts = ['migrated' => 0, 'rejected' => 0, 'reconciled' => 0, 'drifted' => 0];
    foreach (TrainingMigrationLedgerEntry::query()->forCompany($s['tenantId'], $s['companyId'])->get() as $row) {
        $counts[$row->status->value]++;
    }

    return $counts;
}

test('importing the starter workbook writes one migrated ledger row per created profile with sha256 and row; a re-run adds zero', function (): void {
    $f = migLedFixture();
    $a = $f['alpha'];
    $path = migLedCsv([
        ['Operations', 'Line Operator', 'Forklift Operation', '3', 'critical'],
        ['Operations', 'Line Operator', '5S Housekeeping', '2', 'essential'],
        ['Operations', 'Supervisor', 'Shift Handover', '4', 'critical'],
    ]);
    $sha = hash_file('sha256', $path);

    $first = app(StarterProfileImporter::class)->import(
        $a['companyId'], $path, 'starter.csv', StarterProfileImporter::SOURCE_KEY, (int) $a['hr']->id,
    );
    expect($first['profiles'])->toBe(2)
        ->and(migLedCount($a, MigrationLedgerStatus::Migrated))->toBe(2);

    $rows = TrainingMigrationLedgerEntry::query()->forCompany($f['tenantId'], $a['companyId'])
        ->where('status', MigrationLedgerStatus::Migrated)->orderBy('source_row')->get();
    expect($rows)->toHaveCount(2)
        ->and($rows[0]->source_key)->toBe(StarterProfileImporter::SOURCE_KEY)
        ->and($rows[0]->source_sha256)->toBe($sha)
        ->and($rows[0]->source_row)->toBe(2)
        ->and($rows[0]->target_table)->toBe((new RequirementProfile)->getTable())
        ->and($rows[0]->target_id)->not->toBeNull()
        ->and($rows[1]->source_row)->toBe(4);

    $before = migLedCount($a);
    $second = app(StarterProfileImporter::class)->import(
        $a['companyId'], $path, 'starter.csv', StarterProfileImporter::SOURCE_KEY, (int) $a['hr']->id,
    );
    expect($second['profiles'])->toBe(0)
        ->and(migLedCount($a))->toBe($before);

    @unlink($path);
});

test('a rejected workbook row is stored with reason and excerpt; listRejected is company-scoped', function (): void {
    $f = migLedFixture();
    $a = $f['alpha'];
    $b = $f['beta'];
    $ledger = app(MigrationLedger::class);

    $row = $ledger->recordRejected(
        $a['companyId'],
        'legacy-portal',
        hash('sha256', 'workbook-a'),
        7,
        'unknown employee',
        ['employee' => 'E-404', 'skill' => 'Forklift'],
        (int) $a['hr']->id,
    );

    expect($row->status)->toBe(MigrationLedgerStatus::Rejected)
        ->and($row->reason)->toBe('unknown employee')
        ->and($row->payload_excerpt)->toBe(['employee' => 'E-404', 'skill' => 'Forklift']);

    $listed = $ledger->listRejected($a['companyId']);
    expect($listed)->toHaveCount(1)
        ->and((int) $listed->first()->id)->toBe((int) $row->id);

    expect($ledger->listRejected($b['companyId']))->toHaveCount(0);
});

test('recording the same source, sha256 and row twice is refused and leaves one row', function (): void {
    $f = migLedFixture();
    $a = $f['alpha'];
    $ledger = app(MigrationLedger::class);
    $sha = hash('sha256', 'once');

    $ledger->recordMigrated(
        $a['companyId'], 'legacy-portal', $sha, 3,
        (new RequirementProfile)->getTable(), 99, (int) $a['hr']->id,
    );
    expect(migLedCount($a))->toBe(1);

    expect(fn () => $ledger->recordMigrated(
        $a['companyId'], 'legacy-portal', $sha, 3,
        (new RequirementProfile)->getTable(), 100, (int) $a['hr']->id,
    ))->toThrow(InvalidMigrationLedgerException::class);

    expect(migLedCount($a))->toBe(1)
        ->and(TrainingMigrationLedgerEntry::query()->forCompany($f['tenantId'], $a['companyId'])->sole()->target_id)->toBe(99);
});

test('people:migration:reconcile marks reconciled when the target exists and drifted after retirement; dry-run writes nothing', function (): void {
    $f = migLedFixture();
    $a = $f['alpha'];
    $path = migLedCsv([
        ['Operations', 'Line Operator', 'Forklift Operation', '3', 'critical'],
    ]);
    app(StarterProfileImporter::class)->import(
        $a['companyId'], $path, 'starter.csv', StarterProfileImporter::SOURCE_KEY, (int) $a['hr']->id,
    );
    @unlink($path);

    $entry = TrainingMigrationLedgerEntry::query()->forCompany($f['tenantId'], $a['companyId'])->sole();
    $profile = RequirementProfile::query()->forCompany($f['tenantId'], $a['companyId'])->whereKey($entry->target_id)->sole();
    app(RequirementProfileStore::class)->publish($a['companyId'], (int) $profile->id);

    expect(Artisan::call('people:migration:reconcile', [
        '--tenant' => $f['tenantId'], '--company' => $a['companyId'],
    ]))->toBe(0);
    app(TenantContext::class)->set($f['tenantId']);
    expect($entry->fresh()->status)->toBe(MigrationLedgerStatus::Reconciled)
        ->and($entry->fresh()->reconciled_at)->not->toBeNull();

    // Reset to migrated so retirement can drift it again.
    $entry->fresh()->update(['status' => MigrationLedgerStatus::Migrated->value, 'reconciled_at' => null]);
    app(RequirementProfileStore::class)->retire($a['companyId'], (int) $profile->id);

    $before = migLedStatusCounts($a);
    expect(Artisan::call('people:migration:reconcile', [
        '--tenant' => $f['tenantId'], '--company' => $a['companyId'], '--dry-run' => true,
    ]))->toBe(0);
    app(TenantContext::class)->set($f['tenantId']);
    expect(migLedStatusCounts($a))->toBe($before)
        ->and($entry->fresh()->status)->toBe(MigrationLedgerStatus::Migrated);

    expect(Artisan::call('people:migration:reconcile', [
        '--tenant' => $f['tenantId'], '--company' => $a['companyId'],
    ]))->toBe(0);
    app(TenantContext::class)->set($f['tenantId']);
    expect($entry->fresh()->status)->toBe(MigrationLedgerStatus::Drifted);
});

test('the reconcile command refuses a sibling tenant company and touches no row', function (): void {
    $f = migLedFixture();
    $a = $f['alpha'];
    $ledger = app(MigrationLedger::class);
    $ledger->recordMigrated(
        $a['companyId'], 'legacy-portal', hash('sha256', 'x'), 1,
        (new RequirementProfile)->getTable(), 1, (int) $a['hr']->id,
    );
    $before = migLedStatusCounts($a);

    $away = migLedFixture('Away');
    expect(Artisan::call('people:migration:reconcile', [
        '--tenant' => $away['tenantId'], '--company' => $a['companyId'],
    ]))->toBe(1);

    app(TenantContext::class)->set($f['tenantId']);
    expect(migLedStatusCounts($a))->toBe($before);
});

test('the rejected-rows table renders with a caption; a user without migration.view is refused', function (): void {
    $f = migLedFixture();
    $a = $f['alpha'];
    app(MigrationLedger::class)->recordRejected(
        $a['companyId'], 'legacy-portal', hash('sha256', 'rej'), 5,
        'unknown skill', ['skill' => 'Missing'], (int) $a['hr']->id,
    );

    $this->actingAs($a['hr'])->get(route('people.training.migration.index'))
        ->assertOk()
        ->assertSee('Rejected migration rows')
        ->assertSee('unknown skill')
        ->assertSee('legacy-portal');

    Livewire::actingAs($a['hr'])->test(Index::class)
        ->assertOk()
        ->assertSeeHtml('<caption')
        ->assertSee('Rejected migration rows');

    $this->actingAs($a['staff'])->get(route('people.training.migration.index'))->assertForbidden();
});

test('a ledger row whose target table no longer exists is marked drifted, not thrown on', function (): void {
    $f = migLedFixture('MigLedGone');
    $a = $f['alpha'];
    $ledger = app(MigrationLedger::class);

    // A migration ledger outlives the schema it recorded: a table renamed or
    // dropped by a later migration is exactly the drift this command exists to
    // report, not a reason for it to stop.
    $ledger->recordMigrated($a['companyId'], 'starter.csv', str_repeat('a', 64), 1,
        'people_training_table_that_was_dropped', 42, (int) $a['hr']->id);

    $counts = $ledger->reconcile($a['companyId']);

    expect($counts['drifted'])->toBe(1)
        ->and($counts['migrated'])->toBe(0);
});
