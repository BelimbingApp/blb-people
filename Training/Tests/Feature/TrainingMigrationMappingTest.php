<?php

use App\Base\Authz\Enums\PrincipalType;
use App\Base\Authz\Exceptions\AuthorizationDeniedException;
use App\Base\Authz\Models\PrincipalRole;
use App\Base\Authz\Models\Role;
use App\Base\Tenancy\Contracts\TenantContext;
use App\Core\Company\Models\Company;
use App\Core\User\Models\User;
use App\Domains\People\Training\Data\TrainingMigrationFieldMappingDraft;
use App\Domains\People\Training\Data\TrainingMigrationSourceDraft;
use App\Domains\People\Training\Enums\MigrationSourceKind;
use App\Domains\People\Training\Enums\MigrationWorkflow;
use App\Domains\People\Training\Enums\MigrationWriter;
use App\Domains\People\Training\Exceptions\InvalidTrainingMigrationMappingException;
use App\Domains\People\Training\Livewire\Migration\Index;
use App\Domains\People\Training\Models\TrainingMigrationFieldMapping;
use App\Domains\People\Training\Models\TrainingMigrationMappingSignoff;
use App\Domains\People\Training\Models\TrainingMigrationWriterWindow;
use App\Domains\People\Training\Services\TrainingMigrationMappingStore;
use App\Domains\People\Training\Services\TrainingMigrationSourceStore;
use Livewire\Livewire;

/**
 * 0015-b: the field and code mapping register and the one-authoritative-writer
 * rule per workflow and cutover window. Self-contained: helpers are prefixed
 * migMap and live here.
 */
afterEach(function (): void {
    app(TenantContext::class)->clear();
});

beforeEach(function (): void {
    $this->withoutVite();
});

function migMapUser(Company $company, string $roleCode, string $name): User
{
    $user = User::factory()->create(['company_id' => $company->id, 'name' => $name]);
    PrincipalRole::query()->create([
        'company_id' => $company->id, 'principal_type' => PrincipalType::USER->value, 'principal_id' => $user->id,
        'role_id' => Role::query()->whereNull('company_id')->where('code', $roleCode)->sole()->id,
    ]);

    return $user;
}

/** One company side: HR (maps, declares, signs), HOD (reads), an employee (neither), and one recorded source. */
function migMapSide(int $tenantId, Company $company, string $label): array
{
    $hr = migMapUser($company, 'people_hr', $label.' HR');
    $hod = migMapUser($company, 'people_hod', $label.' HOD');
    $staff = migMapUser($company, 'people_employee', $label.' Staff');
    $source = app(TrainingMigrationSourceStore::class)->record($hr, (int) $company->id, new TrainingMigrationSourceDraft(
        sourceKey: 'legacy-portal', name: 'Legacy portal', kind: MigrationSourceKind::Portal, format: 'csv export',
    ));

    return compact('hr', 'hod', 'staff', 'source') + ['company' => $company, 'companyId' => (int) $company->id, 'tenantId' => $tenantId];
}

/** @return array{tenantId: int, alpha: array, beta: array} */
function migMapFixture(string $label = 'MigMap'): array
{
    $tenant = createTenant(['name' => $label.' Tenant']);
    $tenantId = (int) $tenant->id;
    app(TenantContext::class)->set($tenantId);
    setupAuthzRoles();
    $alpha = Company::factory()->create(['tenant_id' => $tenantId, 'name' => $label.' Alpha', 'status' => 'active']);
    $beta = Company::factory()->create(['tenant_id' => $tenantId, 'name' => $label.' Beta', 'status' => 'active']);

    return ['tenantId' => $tenantId, 'alpha' => migMapSide($tenantId, $alpha, $label.' A'), 'beta' => migMapSide($tenantId, $beta, $label.' B')];
}

function migMapDraft(array $s, array $overrides = []): TrainingMigrationFieldMappingDraft
{
    return new TrainingMigrationFieldMappingDraft(...array_replace([
        'sourceId' => (int) $s['source']->id, 'sourceField' => 'SkillLevel', 'sourceCode' => 'L3',
        'targetTable' => 'people_connector_skill_assessments', 'targetColumn' => 'result_band',
        'dedupRule' => 'Latest assessment per employee and skill wins; ties by portal row id.',
    ], $overrides));
}

function migMapDeclare(array $s, string $writer, string $from, ?string $to = null, MigrationWorkflow $workflow = MigrationWorkflow::Assessments): TrainingMigrationWriterWindow
{
    return app(TrainingMigrationMappingStore::class)->declareWriter(
        $s['hr'], $s['companyId'], $workflow, MigrationWriter::from($writer),
        new DateTimeImmutable($from), $to === null ? null : new DateTimeImmutable($to),
    );
}

function migMapWindows(array $s): int
{
    return TrainingMigrationWriterWindow::query()->forCompany($s['tenantId'], $s['companyId'])->count();
}

function migMapMappings(array $s): int
{
    return TrainingMigrationFieldMapping::query()->forCompany($s['tenantId'], $s['companyId'])->count();
}

/** @return array<string, list<array<string, mixed>>> every register row of the company, as stored */
function migMapSnapshot(array $s): array
{
    return [
        'mappings' => TrainingMigrationFieldMapping::query()->forCompany($s['tenantId'], $s['companyId'])->orderBy('id')->get()->map->getAttributes()->all(),
        'windows' => TrainingMigrationWriterWindow::query()->forCompany($s['tenantId'], $s['companyId'])->orderBy('id')->get()->map->getAttributes()->all(),
        'signoffs' => TrainingMigrationMappingSignoff::query()->forCompany($s['tenantId'], $s['companyId'])->orderBy('id')->get()->map->getAttributes()->all(),
    ];
}

test('a mapping row carries the tenant, company, source and acting user; a HOD without manage is refused and the count is unchanged', function (): void {
    $f = migMapFixture();
    $a = $f['alpha'];
    $store = app(TrainingMigrationMappingStore::class);

    $mapping = $store->map($a['hr'], $a['companyId'], migMapDraft($a));

    expect((int) $mapping->tenant_id)->toBe($f['tenantId'])
        ->and((int) $mapping->company_entity_id)->toBe($a['companyId'])
        ->and($mapping->training_migration_source_id)->toBe((int) $a['source']->id)
        ->and($mapping->created_by_user_id)->toBe((int) $a['hr']->id)
        ->and($mapping->source_field)->toBe('SkillLevel')
        ->and($mapping->source_code)->toBe('L3')
        ->and($mapping->target_table)->toBe('people_connector_skill_assessments')
        ->and($mapping->target_column)->toBe('result_band');

    $before = migMapMappings($a);
    expect(fn () => $store->map($a['hod'], $a['companyId'], migMapDraft($a, ['sourceCode' => 'L4'])))
        ->toThrow(AuthorizationDeniedException::class);
    expect(migMapMappings($a))->toBe($before);
});

test('a mapping to a sibling company\'s source, to an unknown target column, or without a dedup rule is refused', function (): void {
    $f = migMapFixture();
    $a = $f['alpha'];
    $b = $f['beta'];
    $store = app(TrainingMigrationMappingStore::class);

    expect(fn () => $store->map($a['hr'], $a['companyId'], migMapDraft($a, ['sourceId' => (int) $b['source']->id])))
        ->toThrow(InvalidTrainingMigrationMappingException::class, 'source was not found in this company');
    expect(fn () => $store->map($a['hr'], $a['companyId'], migMapDraft($a, ['targetColumn' => 'no_such_column'])))
        ->toThrow(InvalidTrainingMigrationMappingException::class, 'target column');
    expect(fn () => $store->map($a['hr'], $a['companyId'], migMapDraft($a, ['dedupRule' => '  '])))
        ->toThrow(InvalidTrainingMigrationMappingException::class, 'dedup rule');
    expect(migMapMappings($a))->toBe(0);
});

test('two windows for the same workflow with different writers that overlap by one day are refused and the second row is never written', function (): void {
    $f = migMapFixture();
    $a = $f['alpha'];
    migMapDeclare($a, 'legacy', '2026-01-01', '2026-03-31');
    $before = migMapWindows($a);

    expect(fn () => migMapDeclare($a, 'people', '2026-03-31', '2026-06-30'))
        ->toThrow(InvalidTrainingMigrationMappingException::class, 'already the authoritative writer');
    expect(fn () => migMapDeclare($a, 'people', '2025-10-01', '2026-01-01'))
        ->toThrow(InvalidTrainingMigrationMappingException::class, 'already the authoritative writer');
    expect(migMapWindows($a))->toBe($before)->toBe(1);
});

test('two windows with the same writer may overlap; two different writers with adjacent windows are accepted; another workflow is unconstrained', function (): void {
    $f = migMapFixture();
    $a = $f['alpha'];
    migMapDeclare($a, 'legacy', '2026-01-01', '2026-03-31');

    $sameWriter = migMapDeclare($a, 'legacy', '2026-02-01', '2026-04-30');
    $adjacent = migMapDeclare($a, 'people', '2026-05-01', null);
    $otherWorkflow = migMapDeclare($a, 'people', '2026-01-01', null, MigrationWorkflow::Attendance);

    expect($sameWriter->writer)->toBe(MigrationWriter::Legacy)
        ->and($adjacent->writer)->toBe(MigrationWriter::People)
        ->and($adjacent->starts_on->toDateString())->toBe('2026-05-01')
        ->and($adjacent->ends_on)->toBeNull()
        ->and($otherWorkflow->workflow)->toBe(MigrationWorkflow::Attendance)
        ->and(migMapWindows($a))->toBe(4);
});

test('an open-ended legacy window refuses any later people window for that workflow, and an ending window is refused if the open one starts inside it', function (): void {
    $f = migMapFixture();
    $a = $f['alpha'];
    migMapDeclare($a, 'legacy', '2026-01-01', null);

    expect(fn () => migMapDeclare($a, 'people', '2027-06-01', '2027-12-31'))
        ->toThrow(InvalidTrainingMigrationMappingException::class, 'already the authoritative writer');
    expect(fn () => migMapDeclare($a, 'people', '2030-01-01', null))
        ->toThrow(InvalidTrainingMigrationMappingException::class, 'already the authoritative writer');
    expect(fn () => migMapDeclare($a, 'people', '2025-01-01', '2026-01-01'))
        ->toThrow(InvalidTrainingMigrationMappingException::class, 'already the authoritative writer');
    expect(migMapWindows($a))->toBe(1);

    $earlier = migMapDeclare($a, 'people', '2025-01-01', '2025-12-31');
    expect($earlier->ends_on->toDateString())->toBe('2025-12-31')->and(migMapWindows($a))->toBe(2);
});

test('a window that ends before it starts, or is declared by a HOD, is refused with the count unchanged', function (): void {
    $f = migMapFixture();
    $a = $f['alpha'];

    expect(fn () => migMapDeclare($a, 'legacy', '2026-03-31', '2026-01-01'))
        ->toThrow(InvalidTrainingMigrationMappingException::class, 'cannot end before it starts');
    expect(fn () => app(TrainingMigrationMappingStore::class)->declareWriter($a['hod'], $a['companyId'], MigrationWorkflow::Assessments, MigrationWriter::Legacy, new DateTimeImmutable('2026-01-01')))
        ->toThrow(AuthorizationDeniedException::class);
    expect(migMapWindows($a))->toBe(0);
});

test('authoritativeWriter() on the boundary days returns the writer whose window includes them and null outside every window', function (): void {
    $f = migMapFixture();
    $a = $f['alpha'];
    $store = app(TrainingMigrationMappingStore::class);
    migMapDeclare($a, 'legacy', '2026-01-01', '2026-03-31');
    migMapDeclare($a, 'people', '2026-04-01', null);

    $writer = fn (string $on): ?string => $store->authoritativeWriter($a['companyId'], MigrationWorkflow::Assessments, new DateTimeImmutable($on.' 23:59:59'));

    expect($writer('2025-12-31'))->toBeNull()
        ->and($writer('2026-01-01'))->toBe('legacy')
        ->and($writer('2026-03-31'))->toBe('legacy')
        ->and($writer('2026-04-01'))->toBe('people')
        ->and($writer('2031-04-01'))->toBe('people')
        ->and($store->authoritativeWriter($a['companyId'], MigrationWorkflow::Attendance, new DateTimeImmutable('2026-02-01')))->toBeNull();
});

test('after signMappings(), map() and declareWriter() are refused for that company, a second signature is refused, and the tables are byte-identical', function (): void {
    $f = migMapFixture();
    $a = $f['alpha'];
    $b = $f['beta'];
    $store = app(TrainingMigrationMappingStore::class);
    $store->map($a['hr'], $a['companyId'], migMapDraft($a));
    migMapDeclare($a, 'legacy', '2026-01-01', '2026-03-31');

    expect($store->signedMappings($a['companyId']))->toBeFalse();
    $signoff = $store->signMappings($a['hr'], $a['companyId'], 'Mapping set agreed with the portal owner.');
    expect($signoff->signed_by_user_id)->toBe((int) $a['hr']->id)
        ->and($signoff->note)->toBe('Mapping set agreed with the portal owner.')
        ->and($store->signedMappings($a['companyId']))->toBeTrue();
    $before = migMapSnapshot($a);

    expect(fn () => $store->map($a['hr'], $a['companyId'], migMapDraft($a, ['sourceCode' => 'L4'])))
        ->toThrow(InvalidTrainingMigrationMappingException::class, 'mapping set is signed');
    expect(fn () => migMapDeclare($a, 'legacy', '2026-04-01', null))
        ->toThrow(InvalidTrainingMigrationMappingException::class, 'mapping set is signed');
    expect(fn () => $store->signMappings($a['hr'], $a['companyId']))
        ->toThrow(InvalidTrainingMigrationMappingException::class, 'already signed');
    expect(fn () => $signoff->update(['note' => 'Rewritten']))->toThrow(InvalidTrainingMigrationMappingException::class, 'cannot be modified');
    expect(fn () => $signoff->delete())->toThrow(InvalidTrainingMigrationMappingException::class, 'cannot be deleted');
    expect(fn () => $store->signMappings($a['hod'], $a['companyId']))->toThrow(AuthorizationDeniedException::class);
    expect(migMapSnapshot($a))->toBe($before);

    // The sibling company is not signed by Alpha's signature and still accepts rows.
    expect($store->signedMappings($b['companyId']))->toBeFalse();
    migMapDeclare($b, 'people', '2026-01-01', null);
    expect(migMapWindows($b))->toBe(1);
});

test('a sibling company\'s and a sibling tenant\'s windows do not constrain the acting company and are never listed', function (): void {
    $f = migMapFixture();
    $g = migMapFixture('Away');
    $away = migMapDeclare($g['alpha'], 'legacy', '2026-01-01', null);
    $awayMapping = app(TrainingMigrationMappingStore::class)->map($g['alpha']['hr'], $g['alpha']['companyId'], migMapDraft($g['alpha']));

    app(TenantContext::class)->set($f['tenantId']);
    $a = $f['alpha'];
    $b = $f['beta'];
    $store = app(TrainingMigrationMappingStore::class);
    $theirs = migMapDeclare($b, 'legacy', '2026-01-01', null);
    $theirMapping = $store->map($b['hr'], $b['companyId'], migMapDraft($b));

    $mine = migMapDeclare($a, 'people', '2026-01-01', null);
    $myMapping = $store->map($a['hr'], $a['companyId'], migMapDraft($a));

    expect($store->writerWindows($a['hr'], $a['companyId'])->pluck('id')->all())->toBe([(int) $mine->id])
        ->and($store->writerWindows($a['hr'], $a['companyId'])->pluck('id')->all())->not->toContain((int) $theirs->id)
        ->and($store->writerWindows($a['hr'], $a['companyId'])->pluck('id')->all())->not->toContain((int) $away->id)
        ->and($store->mappings($a['hr'], $a['companyId'])->pluck('id')->all())->toBe([(int) $myMapping->id])
        ->and($store->mappings($a['hr'], $a['companyId'])->pluck('id')->all())->not->toContain((int) $theirMapping->id)
        ->and($store->mappings($a['hr'], $a['companyId'])->pluck('id')->all())->not->toContain((int) $awayMapping->id)
        ->and($store->authoritativeWriter($a['companyId'], MigrationWorkflow::Assessments, new DateTimeImmutable('2026-02-01')))->toBe('people')
        ->and($store->authoritativeWriter($b['companyId'], MigrationWorkflow::Assessments, new DateTimeImmutable('2026-02-01')))->toBe('legacy');
});

test('the page renders the captioned mapping and writer-window tables for HR and HOD, and the actions go through the store', function (): void {
    $f = migMapFixture();
    $a = $f['alpha'];
    migMapDeclare($a, 'legacy', '2026-01-01', '2026-03-31');
    app(TrainingMigrationMappingStore::class)->map($a['hr'], $a['companyId'], migMapDraft($a));

    $this->actingAs($a['hr'])->get(route('people.training.migration.index'))
        ->assertOk()
        ->assertSee('Field and code mappings')
        ->assertSee('Authoritative writer windows')
        ->assertSee('people_connector_skill_assessments')
        ->assertSee('2026-03-31')
        ->assertSee('Sign the mapping set');
    $this->actingAs($a['hod'])->get(route('people.training.migration.index'))
        ->assertOk()
        ->assertSee('Field and code mappings')
        ->assertSee('Authoritative writer windows')
        ->assertDontSee('Sign the mapping set');

    Livewire::actingAs($a['hr'])->test(Index::class)
        ->call('selectCompany', $a['companyId'])
        ->set('mappingSourceId', (string) $a['source']->id)->set('mappingSourceField', 'CertDate')->set('mappingSourceCode', '')
        ->set('mappingTargetTable', 'people_connector_skill_assessments')->set('mappingTargetColumn', 'assessed_at')
        ->set('mappingDedupRule', 'Latest certificate date wins.')
        ->call('mapField')
        ->assertHasNoErrors();
    expect(migMapMappings($a))->toBe(2);

    Livewire::actingAs($a['hr'])->test(Index::class)
        ->set('windowWorkflow', 'assessments')->set('windowWriter', 'people')->set('windowStartsOn', '2026-03-31')->set('windowEndsOn', '')
        ->call('declareWriter')
        ->assertHasErrors(['windowForm']);
    expect(migMapWindows($a))->toBe(1);

    Livewire::actingAs($a['hr'])->test(Index::class)
        ->set('windowWorkflow', 'assessments')->set('windowWriter', 'people')->set('windowStartsOn', '2026-04-01')->set('windowEndsOn', '')
        ->call('declareWriter')
        ->assertHasNoErrors();
    expect(migMapWindows($a))->toBe(2);

    Livewire::actingAs($a['hr'])->test(Index::class)
        ->set('mappingSignNote', 'Agreed.')
        ->call('signMappings')
        ->assertHasNoErrors();
    expect(app(TrainingMigrationMappingStore::class)->signedMappings($a['companyId']))->toBeTrue();

    Livewire::actingAs($a['hod'])->test(Index::class)
        ->set('windowWorkflow', 'attendance')->set('windowWriter', 'people')->set('windowStartsOn', '2026-04-01')
        ->call('declareWriter')
        ->assertForbidden();
    expect(migMapWindows($a))->toBe(2);
});
