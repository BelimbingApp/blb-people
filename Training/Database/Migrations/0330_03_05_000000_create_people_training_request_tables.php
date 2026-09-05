<?php

use App\Base\Database\Concerns\IncubatingSchema;
use App\Base\Database\Concerns\RegistersTables;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    use IncubatingSchema, RegistersTables;

    public function up(): void
    {
        Schema::create('people_training_requests', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->unsignedBigInteger('company_entity_id');
            $table->uuid('request_key');
            $table->string('requestor_provider_id', 80);
            $table->string('requestor_subject_id', 160);
            $table->string('department_provider_id', 80);
            $table->string('department_subject_id', 160);
            $table->string('need_source', 40);
            $table->text('need');
            $table->text('learning_objective');
            $table->text('expected_result');
            $table->string('priority', 16);
            $table->unsignedBigInteger('skill_gap_assessment_id')->nullable();
            $table->unsignedInteger('requirement_version')->nullable();
            $table->string('status', 24);
            $table->unsignedBigInteger('created_by_user_id');
            $table->timestamps();
            $table->unique(['id', 'tenant_id', 'company_entity_id'], 'ptr_request_owner_uq');
            $table->unique(['tenant_id', 'request_key'], 'ptr_request_key_uq');
            $table->index(['tenant_id', 'company_entity_id', 'status'], 'ptr_request_queue_idx');
            $table->foreign(['company_entity_id', 'tenant_id'], 'ptr_request_company_fk')
                ->references(['id', 'tenant_id'])->on('companies')->restrictOnDelete();
            $table->foreign(['skill_gap_assessment_id', 'tenant_id'], 'ptr_request_gap_fk')
                ->references(['id', 'tenant_id'])->on('people_connector_skill_assessments')->restrictOnDelete();
        });
        Schema::create('people_training_request_decisions', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->unsignedBigInteger('company_entity_id');
            $table->unsignedBigInteger('training_request_id');
            $table->string('decision', 32);
            $table->unsignedBigInteger('actor_user_id');
            $table->text('notes')->nullable();
            $table->timestamp('occurred_at');
            $table->unique(['id', 'tenant_id', 'company_entity_id'], 'ptr_decision_owner_uq');
            $table->foreign(['training_request_id', 'tenant_id', 'company_entity_id'], 'ptr_decision_request_fk')
                ->references(['id', 'tenant_id', 'company_entity_id'])->on('people_training_requests')->restrictOnDelete();
        });
        foreach (['people_training_requests', 'people_training_request_decisions'] as $table) {
            $this->registerTable($table);
        }
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::unprepared(<<<'SQL'
                CREATE FUNCTION ptr_decision_immutable() RETURNS trigger AS $$
                BEGIN RAISE EXCEPTION 'training request decisions are append-only'; END;
                $$ LANGUAGE plpgsql;
                CREATE TRIGGER ptr_decision_immutable BEFORE UPDATE OR DELETE ON people_training_request_decisions
                FOR EACH ROW EXECUTE FUNCTION ptr_decision_immutable();
                SQL);
        } elseif (DB::connection()->getDriverName() === 'sqlite') {
            DB::unprepared(<<<'SQL'
                CREATE TRIGGER ptr_decision_update_guard BEFORE UPDATE ON people_training_request_decisions
                BEGIN SELECT RAISE(ABORT, 'training request decisions are append-only'); END;
                CREATE TRIGGER ptr_decision_delete_guard BEFORE DELETE ON people_training_request_decisions
                BEGIN SELECT RAISE(ABORT, 'training request decisions are append-only'); END;
                SQL);
        }
    }

    public function down(): void
    {
        foreach (['people_training_request_decisions', 'people_training_requests'] as $table) {
            $this->unregisterTable($table);
            Schema::dropIfExists($table);
        }
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::unprepared('DROP FUNCTION IF EXISTS ptr_decision_immutable()');
        }
    }
};
