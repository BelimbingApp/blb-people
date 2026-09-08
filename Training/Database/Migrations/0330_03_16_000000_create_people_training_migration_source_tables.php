<?php

use App\Base\Database\Concerns\IncubatingSchema;
use App\Base\Database\Concerns\RegistersTables;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Migration source inventory (blb-people#397 / [0015-a]).
 *
 * One row per legacy source HR intends to import from, and one append-only
 * sign-off per source. The sign-off table's unique key is what makes "signed"
 * a fact rather than a flag: a source is signed when its row exists, and it
 * cannot be signed twice because the second insert loses.
 */
return new class extends Migration
{
    use IncubatingSchema;
    use RegistersTables;

    /** @var list<string> */
    private array $tables = [
        'people_training_migration_sources',
        'people_training_migration_source_signoffs',
    ];

    public function up(): void
    {
        Schema::create('people_training_migration_sources', function (Blueprint $table): void {
            $this->identity($table, 'ptms');
            $table->string('source_key', 64);
            $table->string('name', 160);
            $table->string('kind', 16);
            $table->unsignedBigInteger('owner_employee_id')->nullable();
            $table->string('format', 64);
            $table->unsignedInteger('estimated_volume')->nullable();
            $table->text('retention_note')->nullable();
            $table->text('data_quality_note')->nullable();
            $table->unsignedBigInteger('created_by_user_id');

            $table->unique(['tenant_id', 'company_entity_id', 'source_key'], 'ptms_key_uq');
        });

        Schema::create('people_training_migration_source_signoffs', function (Blueprint $table): void {
            $this->identity($table, 'ptmss');
            $table->unsignedBigInteger('training_migration_source_id');
            $table->unsignedBigInteger('signed_by_user_id');
            $table->timestamp('signed_at');
            $table->text('note')->nullable();

            $table->unique(['tenant_id', 'training_migration_source_id'], 'ptmss_source_uq');
            $table->foreign(['training_migration_source_id', 'tenant_id', 'company_entity_id'], 'ptmss_source_fk')
                ->references(['id', 'tenant_id', 'company_entity_id'])->on('people_training_migration_sources')
                ->cascadeOnUpdate()->restrictOnDelete();
        });

        foreach ($this->tables as $table) {
            $this->registerTable($table);
        }

        $this->signoffGuards();
    }

    public function down(): void
    {
        $this->dropSignoffGuards();

        foreach (array_reverse($this->tables) as $table) {
            $this->unregisterTable($table);
            Schema::dropIfExists($table);
        }
    }

    /**
     * A sign-off that the query builder can rewrite is not a signature.
     *
     * The model refuses update and delete, but Eloquent does not fire those
     * events for builder-level writes, so a mass update or delete went
     * straight through (#449). The database refuses them for every writer.
     * CREATE OR REPLACE so an incubating rebuild can replay this migration.
     */
    private function signoffGuards(): void
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'pgsql') {
            DB::unprepared(<<<'SQL'
                CREATE OR REPLACE FUNCTION ptmss_signoff_append_only() RETURNS trigger AS $$
                BEGIN
                    RAISE EXCEPTION 'migration source sign-offs are append-only';
                END;
                $$ LANGUAGE plpgsql;
                CREATE TRIGGER ptmss_append_only BEFORE UPDATE OR DELETE
                    ON people_training_migration_source_signoffs
                    FOR EACH ROW EXECUTE FUNCTION ptmss_signoff_append_only();
                SQL);

            return;
        }

        if ($driver === 'sqlite') {
            DB::unprepared(<<<'SQL'
                CREATE TRIGGER people_training_migration_source_signoffs_UPDATE_guard
                BEFORE UPDATE ON people_training_migration_source_signoffs
                BEGIN SELECT RAISE(ABORT, 'migration source sign-offs are append-only'); END;
                CREATE TRIGGER people_training_migration_source_signoffs_DELETE_guard
                BEFORE DELETE ON people_training_migration_source_signoffs
                BEGIN SELECT RAISE(ABORT, 'migration source sign-offs are append-only'); END;
                SQL);
        }
    }

    private function dropSignoffGuards(): void
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'pgsql') {
            DB::unprepared(<<<'SQL'
                DROP TRIGGER IF EXISTS ptmss_append_only ON people_training_migration_source_signoffs;
                DROP FUNCTION IF EXISTS ptmss_signoff_append_only();
                SQL);

            return;
        }

        if ($driver === 'sqlite') {
            DB::unprepared(<<<'SQL'
                DROP TRIGGER IF EXISTS people_training_migration_source_signoffs_UPDATE_guard;
                DROP TRIGGER IF EXISTS people_training_migration_source_signoffs_DELETE_guard;
                SQL);
        }
    }

    private function identity(Blueprint $table, string $prefix): void
    {
        $table->id();
        $table->unsignedBigInteger('tenant_id')->index();
        $table->unsignedBigInteger('company_entity_id');
        $table->timestamps();
        $table->unique(['id', 'tenant_id', 'company_entity_id'], $prefix.'_owner_uq');
        $table->foreign(['company_entity_id', 'tenant_id'], $prefix.'_company_fk')
            ->references(['id', 'tenant_id'])->on('companies')->restrictOnDelete();
    }
};
