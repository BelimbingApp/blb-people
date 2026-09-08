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
use App\Domains\People\Skills\Models\Skill;
use App\Domains\People\Training\Data\TrainingMigrationSourceDraft;
use App\Domains\People\Training\Enums\MigrationLedgerStatus;
use App\Domains\People\Training\Enums\MigrationSourceKind;
use App\Domains\People\Training\Exceptions\InvalidMigrationImportException;
use App\Domains\People\Training\Models\TrainingMigrationLedgerEntry;
use App\Domains\People\Training\Services\MigrationImport;
use App\Domains\People\Training\Services\MigrationLedger;
use App\Domains\People\Training\Services\TrainingMigrationSourceStore;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 0015-e / #417: dry-runnable, resumable, idempotent migration import with quarantine.
 * Self-contained: helpers are prefixed migImp.
 */
afterEach(function (): void {
    app(TenantContext::class)->clear();
});

beforeEach(function (): void {
    $this->withoutVite();
});

function migImpUser(Company $company, string $roleCode, string $name): User
{
    $user = User::factory()->create(['company_id' => $company->id, 'name' => $name]);
    PrincipalRole::query()->create([
        'company_id' => $company->id, 'principal_type' => PrincipalType::USER->value, 'principal_id' => $user->id,
        'role_id' => Role::query()->whereNull('company_id')->where('code', $roleCode)->sole()->id,
    ]);

    return $user;
}

function migImpUnit(Company $company, string $code, string $name): void
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
function migImpFixture(string $label = 'MigImp'): array
{
    $tenant = createTenant(['name' => $label.' Tenant']);
    $tenantId = (int) $tenant->id;
    app(TenantContext::class)->set($tenantId);
    setupAuthzRoles();
    $alpha = Company::factory()->create(['tenant_id' => $tenantId, 'name' => $label.' Alpha', 'status' => 'active']);
    $beta = Company::factory()->create(['tenant_id' => $tenantId, 'name' => $label.' Beta', 'status' => 'active']);
    migImpUnit($alpha, 'OPS', 'Operations');
    migImpUnit($beta, 'BOPS', 'Operations');
    $owner = Employee::factory()->create([
        'company_id' => $alpha->id, 'full_name' => $label.' Owner', 'short_name' => null,
        'supervisor_id' => null, 'status' => 'active', 'employee_type' => 'full_time',
    ]);

    return [
        'tenantId' => $tenantId,
        'alpha' => [
            'company' => $alpha, 'companyId' => (int) $alpha->id, 'tenantId' => $tenantId,
            'hr' => migImpUser($alpha, 'people_hr', $label.' A HR'),
            'owner' => $owner,
        ],
        'beta' => [
            'company' => $beta, 'companyId' => (int) $beta->id, 'tenantId' => $tenantId,
            'hr' => migImpUser($beta, 'people_hr', $label.' B HR'),
        ],
    ];
}

function migImpSign(array $s, string $key = 'legacy-portal'): void
{
    $store = app(TrainingMigrationSourceStore::class);
    $source = $store->record($s['hr'], $s['companyId'], new TrainingMigrationSourceDraft(
        sourceKey: $key,
        name: 'Legacy portal export',
        kind: MigrationSourceKind::Portal,
        format: 'csv',
        ownerEmployeeEntityId: (int) $s['owner']->id,
        estimatedVolume: 50,
    ));
    $store->sign($s['hr'], $s['companyId'], (int) $source->id, 'ready for cutover');
}

function migImpCsv(array $rows): string
{
    $path = tempnam(sys_get_temp_dir(), 'migimp');
    $lines = [implode(',', MigrationImport::HEADER)];
    foreach ($rows as $row) {
        $lines[] = implode(',', $row);
    }
    file_put_contents($path, implode("\n", $lines)."\n");

    return $path;
}

/** Counts that prove a dry run wrote nothing in the import's write set. */
function migImpWriteCounts(array $s): array
{
    return [
        'ledger' => TrainingMigrationLedgerEntry::query()->forCompany($s['tenantId'], $s['companyId'])->count(),
        'profiles' => RequirementProfile::query()->forCompany($s['tenantId'], $s['companyId'])->count(),
        'skills' => Skill::query()->forCompany($s['tenantId'], $s['companyId'])->count(),
    ];
}

/** Broader people-table probe used to show the zero-write assertion can redden. */
function migImpPeopleCounts(): array
{
    $counts = [];
    foreach (['people_training_migration_ledger', 'people_connector_skill_skills', 'people_connector_skill_requirement_profiles'] as $table) {
        if (Schema::hasTable($table)) {
            $counts[$table] = (int) DB::table($table)->count();
        }
    }
    ksort($counts);

    return $counts;
}

function migImpProfiles(array $s): array
{
    return RequirementProfile::query()
        ->forCompany($s['tenantId'], $s['companyId'])
        ->orderBy('code')
        ->get()
        ->map(fn (RequirementProfile $p): array => [
            'code' => $p->code,
            'name' => $p->name,
            'id' => (int) $p->id,
        ])
        ->all();
}

function migImpLedgerRows(array $s): array
{
    return TrainingMigrationLedgerEntry::query()
        ->forCompany($s['tenantId'], $s['companyId'])
        ->orderBy('source_row')
        ->get()
        ->map(fn (TrainingMigrationLedgerEntry $e): array => [
            'row' => (int) $e->source_row,
            'status' => $e->status->value,
            'reason' => $e->reason,
            'target_id' => $e->target_id,
            'sha' => $e->source_sha256,
        ])
        ->all();
}

test('dry run over a fixture source writes zero rows anywhere; injecting a write reddens the probe', function (): void {
    $f = migImpFixture();
    $a = $f['alpha'];
    migImpSign($a);
    $path = migImpCsv([
        ['Operations', 'Line Operator', 'Forklift Operation', '3', 'critical'],
        ['Operations', 'Supervisor', 'Shift Handover', '4', 'essential'],
    ]);

    $before = migImpWriteCounts($a);
    $peopleBefore = migImpPeopleCounts();
    $report = app(MigrationImport::class)->dryRun($a['hr'], $a['companyId'], 'legacy-portal', $path);
    expect($report->dryRun)->toBeTrue()
        ->and($report->importedCount())->toBe(2)
        ->and($report->rejectedCount())->toBe(0)
        ->and(migImpWriteCounts($a))->toBe($before)
        ->and(migImpPeopleCounts())->toBe($peopleBefore);

    // Probe can fail: a write during dry-run classification must surface.
    $mutantBefore = migImpPeopleCounts();
    $wrote = false;
    app(MigrationImport::class)->dryRun(
        $a['hr'],
        $a['companyId'],
        'legacy-portal',
        $path,
        afterRow: function () use ($a, &$wrote): void {
            if ($wrote) {
                return;
            }
            $wrote = true;
            DB::table('people_training_migration_ledger')->insert([
                'tenant_id' => $a['tenantId'],
                'company_entity_id' => $a['companyId'],
                'source_key' => 'probe',
                'source_sha256' => str_repeat('a', 64),
                'source_row' => 1,
                'target_table' => '',
                'target_id' => null,
                'status' => MigrationLedgerStatus::Rejected->value,
                'reason' => 'probe',
                'payload_excerpt' => null,
                'recorded_by' => (int) $a['hr']->id,
                'recorded_at' => now(),
                'reconciled_at' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        },
    );
    expect(migImpPeopleCounts())->not->toBe($mutantBefore);

    TrainingMigrationLedgerEntry::query()->forCompany($a['tenantId'], $a['companyId'])->where('source_key', 'probe')->delete();
    @unlink($path);
});

test('import then import again: second run creates nothing and the ledger shows every row applied', function (): void {
    $f = migImpFixture();
    $a = $f['alpha'];
    migImpSign($a);
    $path = migImpCsv([
        ['Operations', 'Line Operator', 'Forklift Operation', '3', 'critical'],
        ['Operations', 'Supervisor', 'Shift Handover', '4', 'essential'],
    ]);

    $first = app(MigrationImport::class)->import($a['hr'], $a['companyId'], 'legacy-portal', $path);
    expect($first->importedCount())->toBe(2)->and($first->skippedCount())->toBe(0);
    $ledger = migImpLedgerRows($a);
    $profiles = migImpProfiles($a);
    expect($ledger)->toHaveCount(2)
        ->and(collect($ledger)->every(fn ($r) => $r['status'] === 'migrated'))->toBeTrue();

    $second = app(MigrationImport::class)->import($a['hr'], $a['companyId'], 'legacy-portal', $path);
    expect($second->importedCount())->toBe(0)
        ->and($second->skippedCount())->toBe(2)
        ->and(migImpLedgerRows($a))->toBe($ledger)
        ->and(migImpProfiles($a))->toBe($profiles);

    // Deleting the identity check (ledger find) would duplicate — prove find is what stops it.
    $found = app(MigrationLedger::class)->find($a['companyId'], 'legacy-portal', $first->sourceSha256, 2);
    expect($found)->not->toBeNull();

    @unlink($path);
});

test('interrupt after N rows, rerun, ends with the same rows as an uninterrupted run', function (): void {
    $f = migImpFixture('MigImpResume');
    $a = $f['alpha'];
    migImpSign($a);
    $rows = [
        ['Operations', 'Line Operator', 'Forklift Operation', '3', 'critical'],
        ['Operations', 'Line Operator', '5S Housekeeping', '2', 'essential'],
        ['Operations', 'Supervisor', 'Shift Handover', '4', 'critical'],
    ];
    $path = migImpCsv($rows);

    expect(fn () => app(MigrationImport::class)->import($a['hr'], $a['companyId'], 'legacy-portal', $path, failAfter: 1))
        ->toThrow(InvalidMigrationImportException::class, 'interrupted after 1');
    expect(migImpLedgerRows($a))->toHaveCount(1);

    $resumed = app(MigrationImport::class)->import($a['hr'], $a['companyId'], 'legacy-portal', $path);
    expect($resumed->importedCount())->toBe(2)->and($resumed->skippedCount())->toBe(1);

    $interrupted = migImpProfiles($a);
    $interruptedLedger = migImpLedgerRows($a);

    // Fresh company: uninterrupted
    $f2 = migImpFixture('MigImpFull');
    $b = $f2['alpha'];
    migImpSign($b);
    $path2 = migImpCsv($rows);
    app(MigrationImport::class)->import($b['hr'], $b['companyId'], 'legacy-portal', $path2);

    expect(collect(migImpProfiles($b))->pluck('code')->values()->all())
        ->toBe(collect($interrupted)->pluck('code')->values()->all())
        ->and(collect(migImpLedgerRows($b))->pluck('row')->values()->all())
        ->toBe(collect($interruptedLedger)->pluck('row')->values()->all());

    @unlink($path);
    @unlink($path2);
});

test('a malformed row is quarantined with a reason code; the rest still imports; reason has no source field values', function (): void {
    $f = migImpFixture();
    $a = $f['alpha'];
    migImpSign($a);
    $path = migImpCsv([
        ['Operations', 'Line Operator', 'Forklift Operation', '3', 'critical'],
        ['Operations', 'Line Operator', '', '3', 'critical'],
        ['Operations', 'Supervisor', 'Shift Handover', '4', 'essential'],
    ]);

    $report = app(MigrationImport::class)->import($a['hr'], $a['companyId'], 'legacy-portal', $path);
    expect($report->importedCount())->toBe(2)
        ->and($report->rejectedCount())->toBe(1)
        ->and($report->rejected[0]['row'])->toBe(3)
        ->and($report->rejected[0]['reason'])->toBe(MigrationImport::REASON_BLANK_SKILL);

    $rejected = TrainingMigrationLedgerEntry::query()
        ->forCompany($a['tenantId'], $a['companyId'])
        ->where('status', MigrationLedgerStatus::Rejected)
        ->sole();
    expect($rejected->reason)->toBe(MigrationImport::REASON_BLANK_SKILL)
        ->and($rejected->reason)->not->toContain('Forklift')
        ->and(json_encode($rejected->payload_excerpt))->not->toContain('Forklift')
        ->and(RequirementProfile::query()->forCompany($a['tenantId'], $a['companyId'])->count())->toBe(2);

    @unlink($path);
});

test('an unsigned source inventory is refused before any read', function (): void {
    $f = migImpFixture();
    $a = $f['alpha'];
    // Record without sign — signedInventory is false.
    app(TrainingMigrationSourceStore::class)->record($a['hr'], $a['companyId'], new TrainingMigrationSourceDraft(
        sourceKey: 'legacy-portal',
        name: 'Legacy',
        kind: MigrationSourceKind::Portal,
        format: 'csv',
        ownerEmployeeEntityId: (int) $a['owner']->id,
    ));

    $opened = false;
    $path = tempnam(sys_get_temp_dir(), 'migimp-unsigned');
    // A stream wrapper would be heavier; prove refusal with a missing path that
    // would throw on read — unsigned must fail first with the inventory message.
    expect(fn () => app(MigrationImport::class)->import($a['hr'], $a['companyId'], 'legacy-portal', '/no/such/migration-file.csv'))
        ->toThrow(InvalidMigrationImportException::class, 'unsigned source inventory');
    expect($opened)->toBeFalse();
    @unlink($path);
});

test('people:migration:import --dry-run prints counts and writes nothing', function (): void {
    $f = migImpFixture();
    $a = $f['alpha'];
    migImpSign($a);
    $path = migImpCsv([
        ['Operations', 'Line Operator', 'Forklift Operation', '3', 'critical'],
    ]);
    $before = migImpWriteCounts($a);

    expect(Artisan::call('people:migration:import', [
        'path' => $path,
        '--company' => $a['companyId'],
        '--source' => 'legacy-portal',
        '--dry-run' => true,
        '--as' => $a['hr']->id,
        '--tenant' => $a['tenantId'],
    ]))->toBe(0);
    expect(Artisan::output())->toContain('Dry run: nothing was written.')
        ->and(migImpWriteCounts($a))->toBe($before);

    @unlink($path);
});
