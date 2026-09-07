<?php

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
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('people_connector_skill_reassessment_requests', function (Blueprint $table): void {
            $table->string('source', 24)->default('hod')->after('status');
            $table->unsignedBigInteger('source_participation_fact_id')->nullable()->after('source');
            $table->index(['tenant_id', 'company_entity_id', 'source_participation_fact_id'], 'pcr_req_source_fact_idx');
        });

        if (DB::connection()->getDriverName() === 'pgsql') {
            Schema::table('people_connector_skill_reassessment_requests', function (Blueprint $table): void {
                $table->foreign(['source_participation_fact_id', 'tenant_id', 'company_entity_id'], 'pcr_req_source_fact_fk')
                    ->references(['id', 'tenant_id', 'company_entity_id'])->on('people_training_participation_facts')->restrictOnDelete();
            });
        } elseif (DB::connection()->getDriverName() === 'sqlite') {
            foreach (['insert' => 'INSERT', 'update' => 'UPDATE'] as $name => $verb) {
                DB::unprepared(<<<SQL
                    CREATE TRIGGER pcr_req_source_fact_{$name}_guard BEFORE {$verb} ON people_connector_skill_reassessment_requests
                    WHEN NEW.source_participation_fact_id IS NOT NULL AND NOT EXISTS (
                        SELECT 1 FROM people_training_participation_facts f
                        WHERE f.id = NEW.source_participation_fact_id AND f.tenant_id = NEW.tenant_id AND f.company_entity_id = NEW.company_entity_id)
                    BEGIN SELECT RAISE(ABORT, 'a reassessment request names only a participation fact of its own tenant and company'); END;
                    SQL);
            }
        }
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            Schema::table('people_connector_skill_reassessment_requests', function (Blueprint $table): void {
                $table->dropForeign('pcr_req_source_fact_fk');
            });
        } elseif (DB::connection()->getDriverName() === 'sqlite') {
            foreach (['insert', 'update'] as $name) {
                DB::unprepared("DROP TRIGGER IF EXISTS pcr_req_source_fact_{$name}_guard");
            }
        }
        Schema::table('people_connector_skill_reassessment_requests', function (Blueprint $table): void {
            $table->dropIndex('pcr_req_source_fact_idx');
            $table->dropColumn(['source', 'source_participation_fact_id']);
        });
    }
};
