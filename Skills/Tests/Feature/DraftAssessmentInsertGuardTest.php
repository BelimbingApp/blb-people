<?php

use App\Base\Tenancy\Contracts\TenantContext;
use App\Domains\People\Provider\Enums\WorkforceResourceType;
use App\Domains\People\Skills\Data\SkillDraft;
use App\Domains\People\Skills\Enums\AssessmentMethod;
use App\Domains\People\Skills\Enums\CriticalClassification;
use App\Domains\People\Skills\Enums\SkillScope;
use App\Domains\People\Skills\Exceptions\InvalidAssessmentException;
use App\Domains\People\Skills\Services\SkillCatalogStore;
use App\Domains\People\Skills\Tests\Support\NativeWorkforceFixture;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

afterEach(function (): void {
    app(TenantContext::class)->clear();
});

function guardedAssessmentDraft(): array
{
    $tenant = createTenant(['name' => 'Draft insert guard']);
    $tenantId = (int) $tenant->id;
    app(TenantContext::class)->set($tenantId);
    $company = NativeWorkforceFixture::create($tenantId, WorkforceResourceType::Company);
    $employee = NativeWorkforceFixture::create($tenantId, WorkforceResourceType::Employee);
    $catalog = app(SkillCatalogStore::class);
    $category = $catalog->defineCategory((int) $company->id, 'guard', 'Guard');
    $skill = $catalog->defineSkill((int) $company->id, new SkillDraft(
        code: 'draft.guard',
        name: 'Draft guard',
        definition: 'A draft cannot claim a later lifecycle decision.',
        categoryId: (int) $category->id,
        scope: SkillScope::Shared,
        criticalClassification: CriticalClassification::Safety,
        evidenceGuide: 'Observed exercise.',
        defaultAssessmentMethod: AssessmentMethod::DirectObservation,
        defaultReassessmentMonths: 12,
    ));

    return [
        'tenant_id' => $tenantId,
        'company_entity_id' => (int) $company->id,
        'employee_entity_id' => (int) $employee->id,
        'skill_id' => (int) $skill->id,
        'requirement_reference' => 'draft.guard',
        'requirement_version' => 1,
        'required_level' => 2,
        'criticality' => 'critical',
        'method' => 'direct_observation',
        'cycle' => 'annual',
        'status' => 'draft',
        'hod_verification' => 'pending',
    ];
}

dataset('invalid draft lifecycle fields', [
    'finalized timestamp' => [['finalized_at' => '2026-09-01 12:00:00']],
    'finalizing actor' => [['finalized_by_user_id' => 42]],
    'verified HOD decision' => [['hod_verification' => 'verified']],
    'rejected HOD decision' => [['hod_verification' => 'rejected']],
]);

test('draft inserts cannot carry finalization or a HOD decision', function (array $invalid): void {
    $draft = guardedAssessmentDraft();

    expect(fn () => DB::transaction(fn () => DB::table('people_connector_skill_assessments')
        ->insert(array_replace($draft, $invalid))))->toThrow(QueryException::class);

    expect(DB::table('people_connector_skill_assessments')
        ->where('tenant_id', $draft['tenant_id'])->exists())->toBeFalse();
})->with('invalid draft lifecycle fields');

test('an ordinary draft remains writable without workflow authority', function (): void {
    $draft = guardedAssessmentDraft();
    $id = DB::table('people_connector_skill_assessments')->insertGetId($draft);
    $saved = DB::table('people_connector_skill_assessments')->find($id);

    expect($saved->status)->toBe('draft')
        ->and($saved->hod_verification)->toBe('pending')
        ->and($saved->finalized_at)->toBeNull()
        ->and($saved->finalized_by_user_id)->toBeNull();
});

function draftInsertParityMigration(): Migration
{
    return require __DIR__.'/../../Database/Migrations/0330_02_06_000000_align_draft_assessment_insert_guards.php';
}

/**
 * Reproduce the legacy SQLite schema on a disposable connection, including in
 * PostgreSQL CI. Never remove a guard from the application's test database.
 */
function withLegacyDraftInsertDatabase(Closure $test): void
{
    $default = DB::getDefaultConnection();
    $name = 'draft_insert_upgrade_test';
    $previous = config("database.connections.{$name}");
    config(["database.connections.{$name}" => [
        'driver' => 'sqlite',
        'database' => ':memory:',
        'prefix' => '',
        'foreign_key_constraints' => true,
    ]]);
    DB::setDefaultConnection($name);

    try {
        Schema::create('people_connector_skill_assessments', function (Blueprint $table): void {
            $table->id();
            $table->string('status');
            $table->string('hod_verification');
            $table->timestamp('finalized_at')->nullable();
            $table->unsignedBigInteger('finalized_by_user_id')->nullable();
        });
        $test();
    } finally {
        DB::setDefaultConnection($default);
        DB::purge($name);
        config(["database.connections.{$name}" => $previous]);
    }
}

test('upgrade refuses each legacy invalid draft without changing history or installing a partial guard', function (array $invalid): void {
    withLegacyDraftInsertDatabase(function () use ($invalid): void {
        DB::table('people_connector_skill_assessments')->insert(array_replace([
            'status' => 'draft',
            'hod_verification' => 'pending',
        ], $invalid));
        $before = DB::table('people_connector_skill_assessments')->get()->toJson();

        expect(fn () => draftInsertParityMigration()->up())->toThrow(InvalidAssessmentException::class)
            ->and(DB::table('people_connector_skill_assessments')->get()->toJson())->toBe($before)
            ->and(DB::table('sqlite_master')->where('type', 'trigger')->count())->toBe(0);
    });
})->with('invalid draft lifecycle fields');

test('clean upgrade is replayable and rollback removes only the new guard', function (): void {
    withLegacyDraftInsertDatabase(function (): void {
        $table = 'people_connector_skill_assessments';
        DB::table($table)->insert([
            'status' => 'finalized',
            'hod_verification' => 'verified',
            'finalized_at' => '2026-09-01 12:00:00',
            'finalized_by_user_id' => 42,
        ]);
        DB::unprepared("CREATE TRIGGER legacy_assessment_delete_guard BEFORE DELETE ON {$table} BEGIN SELECT RAISE(ABORT, 'history'); END;");
        $before = DB::table($table)->get()->toJson();
        $migration = draftInsertParityMigration();
        $migration->up();
        $migration->up();

        $invalid = ['status' => 'draft', 'hod_verification' => 'verified'];
        expect(fn () => DB::table($table)->insert($invalid))->toThrow(QueryException::class)
            ->and(DB::table('sqlite_master')->where('type', 'trigger')->count())->toBe(2)
            ->and(DB::table($table)->get()->toJson())->toBe($before);

        // Later lifecycle rows remain the existing workflow guard's responsibility.
        DB::table($table)->insert([
            'status' => 'finalized',
            'hod_verification' => 'verified',
            'finalized_at' => '2026-09-02 12:00:00',
            'finalized_by_user_id' => 43,
        ]);
        expect(DB::table($table)->where('status', 'finalized')->count())->toBe(2);

        $migration->down();
        expect(DB::table('sqlite_master')->where('type', 'trigger')->pluck('name')->all())
            ->toBe(['legacy_assessment_delete_guard']);
        $migration->up();
        expect(fn () => DB::table($table)->insert($invalid))->toThrow(QueryException::class)
            ->and(fn () => DB::table($table)->delete())->toThrow(QueryException::class);
        DB::table($table)->insert(['status' => 'draft', 'hod_verification' => 'pending']);
        expect(DB::table($table)->where('status', 'draft')->value('hod_verification'))->toBe('pending');
    });
});
