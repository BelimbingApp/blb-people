<?php

use App\Base\Database\Concerns\IncubatingSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Where a reassessment request came from (0006-e).
 *
 * `source` names the trigger: `hod` for the team-gaps request (0006-b),
 * `training` for a confirmed participation fact with a pass or certificate.
 * A training-sourced row points at the fact it came from, guarded to the
 * same tenant and company: a composite foreign key on PostgreSQL, a trigger
 * on SQLite, where a composite key cannot be added to an existing table.
 *
 * Lives in Training rather than Skills because the guard references
 * people_training_participation_facts, which 0330_03_03 creates; a Skills
 * (0330_02) migration would run before that table exists and abort on
 * PostgreSQL. Declares IncubatingSchema because it alters
 * people_connector_skill_reassessment_requests, which 0330_02_08 creates as
 * incubating (see the note in 0330_03_13 add_training_request_event_link).
 */
return new class extends Migration
{
    use IncubatingSchema;

    public function up(): void
    {
        Schema::table('people_connector_skill_reassessment_requests', function (Blueprint $table): void {
            $table->string('source', 24)->default('hod')->after('status');
            $table->unsignedBigInteger('source_participation_fact_id')->nullable()->after('source');
            $table->index(['tenant_id', 'company_entity_id', 'source_participation_fact_id'], 'pcr_req_source_fact_idx');
        });

        $driver = DB::connection()->getDriverName();

        if ($driver === 'pgsql') {
            $this->createPostgresForeignKey();
        } elseif ($driver === 'sqlite') {
            $this->createSqliteGuards();
        }
    }

    public function down(): void
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'pgsql') {
            Schema::table('people_connector_skill_reassessment_requests', function (Blueprint $table): void {
                $table->dropForeign('pcr_req_source_fact_fk');
            });
        } elseif ($driver === 'sqlite') {
            DB::statement('DROP TRIGGER IF EXISTS pcr_req_source_fact_insert_guard');
            DB::statement('DROP TRIGGER IF EXISTS pcr_req_source_fact_update_guard');
        }

        Schema::table('people_connector_skill_reassessment_requests', function (Blueprint $table): void {
            $table->dropIndex('pcr_req_source_fact_idx');
            $table->dropColumn(['source', 'source_participation_fact_id']);
        });
    }

    private function createPostgresForeignKey(): void
    {
        Schema::table('people_connector_skill_reassessment_requests', function (Blueprint $table): void {
            $table->foreign(['source_participation_fact_id', 'tenant_id', 'company_entity_id'], 'pcr_req_source_fact_fk')
                ->references(['id', 'tenant_id', 'company_entity_id'])->on('people_training_participation_facts')->restrictOnDelete();
        });
    }

    private function createSqliteGuards(): void
    {
        // One statement per trigger, built from plain string literals: the
        // schema-drift verifier reads migration source statically (see the
        // note in 0330_03_02 createSqliteGuards).
        DB::statement(
            'CREATE TRIGGER pcr_req_source_fact_insert_guard'
            .' BEFORE INSERT ON people_connector_skill_reassessment_requests'
            .' WHEN NEW.source_participation_fact_id IS NOT NULL AND NOT EXISTS ('
            .'SELECT 1 FROM people_training_participation_facts f'
            .' WHERE f.id = NEW.source_participation_fact_id AND f.tenant_id = NEW.tenant_id AND f.company_entity_id = NEW.company_entity_id)'
            ." BEGIN SELECT RAISE(ABORT, 'a reassessment request names only a participation fact of its own tenant and company'); END",
        );

        DB::statement(
            'CREATE TRIGGER pcr_req_source_fact_update_guard'
            .' BEFORE UPDATE ON people_connector_skill_reassessment_requests'
            .' WHEN NEW.source_participation_fact_id IS NOT NULL AND NOT EXISTS ('
            .'SELECT 1 FROM people_training_participation_facts f'
            .' WHERE f.id = NEW.source_participation_fact_id AND f.tenant_id = NEW.tenant_id AND f.company_entity_id = NEW.company_entity_id)'
            ." BEGIN SELECT RAISE(ABORT, 'a reassessment request names only a participation fact of its own tenant and company'); END",
        );
    }
};
