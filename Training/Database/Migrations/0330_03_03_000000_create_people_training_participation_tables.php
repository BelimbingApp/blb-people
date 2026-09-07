<?php

use App\Base\Database\Concerns\IncubatingSchema;
use App\Base\Database\Concerns\RegistersTables;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    use IncubatingSchema;
    use RegistersTables;

    private array $tables = ['people_training_sessions', 'people_training_participants', 'people_training_participation_facts'];

    public function up(): void
    {
        Schema::create('people_training_sessions', function (Blueprint $table): void {
            $this->identity($table, 'pts');
            $table->unsignedBigInteger('event_id');
            $table->unique(['id', 'tenant_id', 'company_entity_id', 'event_id'], $table->getTable().'_event_owner_uq');
            $table->string('session_reference', 160);
            $table->timestamp('starts_at');
            $table->timestamp('ends_at');
            $table->unsignedBigInteger('created_by_user_id');
            $table->unique(['tenant_id', 'event_id', 'session_reference'], 'pts_reference_uq');
            $this->parent($table, 'event_id', 'people_connector_training_events', 'pts_event_fk');
        });
        Schema::create('people_training_participants', function (Blueprint $table): void {
            $this->identity($table, 'ptp');
            $table->unsignedBigInteger('event_id');
            $table->unique(['id', 'tenant_id', 'company_entity_id', 'event_id'], $table->getTable().'_event_owner_uq');
            $table->string('provider_id', 80);
            $table->string('employee_subject_id', 160);
            $table->timestamp('workforce_observed_at');
            $table->timestamp('withdrawn_at')->nullable();
            $table->unsignedBigInteger('withdrawn_by_user_id')->nullable();
            $table->unique(['tenant_id', 'event_id', 'provider_id', 'employee_subject_id'], 'ptp_subject_uq');
            $this->parent($table, 'event_id', 'people_connector_training_events', 'ptp_event_fk');
        });
        Schema::create('people_training_participation_facts', function (Blueprint $table): void {
            $this->identity($table, 'ptf');
            $table->unsignedBigInteger('event_id');
            $table->unsignedBigInteger('participant_id');
            $table->unsignedBigInteger('session_id');
            $table->string('attendance', 24);
            $table->unsignedInteger('actual_minutes');
            $table->json('pre_test')->nullable();
            $table->json('post_test')->nullable();
            $table->string('certificate_reference', 160)->nullable();
            $table->date('certificate_valid_from')->nullable();
            $table->date('certificate_valid_until')->nullable();
            $table->json('evidence_references');
            $table->string('source', 80);
            $table->string('source_reference', 160);
            $table->unsignedBigInteger('recorded_by_user_id');
            $table->string('recorded_capability', 100);
            $table->timestamp('recorded_at');
            $table->unsignedBigInteger('confirmed_by_user_id')->nullable();
            $table->string('confirmed_capability', 100)->nullable();
            $table->timestamp('confirmed_at')->nullable();
            // A confirmed fact is immutable (see the guards below), so a
            // correction is a new row pointing at the one it replaces. Zero
            // means "this is the original", which keeps the column NOT NULL
            // and keeps the unique key below expressible: one original and at
            // most one correction per superseded row. Correcting a correction
            // supersedes the latest, so the chain stays linear.
            $table->unsignedBigInteger('supersedes_fact_id')->default(0);
            $table->string('correction_reason', 2000)->nullable();
            $table->unique(['tenant_id', 'participant_id', 'session_id', 'supersedes_fact_id'], 'ptf_session_uq');
            $table->unique(['tenant_id', 'company_entity_id', 'source', 'source_reference'], 'ptf_source_uq');
            foreach (['participant_id' => 'people_training_participants', 'session_id' => 'people_training_sessions'] as $column => $parent) {
                $table->foreign([$column, 'tenant_id', 'company_entity_id', 'event_id'], 'ptf_'.$column.'_fk')
                    ->references(['id', 'tenant_id', 'company_entity_id', 'event_id'])->on($parent)->restrictOnDelete();
            }
        });

        foreach ($this->tables as $table) {
            $this->registerTable($table);
        }
        $this->guards();
    }

    public function down(): void
    {
        foreach (array_reverse($this->tables) as $table) {
            $this->unregisterTable($table);
            Schema::dropIfExists($table);
        }
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::unprepared('DROP FUNCTION IF EXISTS pt_participation_immutable()');
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

    private function parent(Blueprint $table, string $column, string $parent, string $name): void
    {
        $table->foreign([$column, 'tenant_id', 'company_entity_id'], $name)
            ->references(['id', 'tenant_id', 'company_entity_id'])->on($parent)->restrictOnDelete();
    }

    private function guards(): void
    {
        $driver = DB::connection()->getDriverName();
        if ($driver === 'pgsql') {
            DB::unprepared(<<<'SQL'
                CREATE FUNCTION pt_participation_immutable() RETURNS trigger AS $$
                BEGIN
                    -- Statement-level branch first: this function is shared by
                    -- the sessions, participants and facts triggers, and only
                    -- participants rows carry provider_id. Comparing
                    -- participant identity columns inside a single AND chain
                    -- lets PostgreSQL evaluate OLD.provider_id for sessions
                    -- and facts rows (AND operand order is not guaranteed),
                    -- raising 42703 instead of the intended verdict below.
                    IF TG_OP = 'UPDATE' AND TG_TABLE_NAME = 'people_training_participants' THEN
                        IF OLD.tenant_id IS NOT DISTINCT FROM NEW.tenant_id
                            AND OLD.company_entity_id IS NOT DISTINCT FROM NEW.company_entity_id
                            AND OLD.event_id IS NOT DISTINCT FROM NEW.event_id
                            AND OLD.provider_id IS NOT DISTINCT FROM NEW.provider_id
                            AND OLD.employee_subject_id IS NOT DISTINCT FROM NEW.employee_subject_id
                            AND OLD.workforce_observed_at IS NOT DISTINCT FROM NEW.workforce_observed_at
                            AND OLD.created_at IS NOT DISTINCT FROM NEW.created_at THEN
                            RETURN NEW;
                        END IF;
                    END IF;
                    IF TG_TABLE_NAME <> 'people_training_participation_facts' THEN
                        RAISE EXCEPTION 'participation identity and sessions are immutable';
                    END IF;
                    IF OLD.confirmed_at IS NOT NULL THEN
                        RAISE EXCEPTION 'participation identity, sessions and confirmed facts are immutable';
                    END IF;
                    IF TG_OP = 'DELETE' THEN RETURN OLD; END IF;
                    RETURN NEW;
                END;
                $$ LANGUAGE plpgsql;
                CREATE TRIGGER pt_immutable BEFORE UPDATE OR DELETE ON people_training_sessions
                    FOR EACH ROW EXECUTE FUNCTION pt_participation_immutable();
                CREATE TRIGGER pt_immutable BEFORE UPDATE OR DELETE ON people_training_participants
                    FOR EACH ROW EXECUTE FUNCTION pt_participation_immutable();
                CREATE TRIGGER pt_immutable BEFORE UPDATE OR DELETE ON people_training_participation_facts
                    FOR EACH ROW EXECUTE FUNCTION pt_participation_immutable();
                SQL);
        } elseif ($driver === 'sqlite') {
            DB::unprepared(<<<'SQL'
                CREATE TRIGGER people_training_sessions_UPDATE_guard BEFORE UPDATE ON people_training_sessions
                BEGIN SELECT RAISE(ABORT, 'participation identity and sessions are immutable'); END;
                CREATE TRIGGER people_training_sessions_DELETE_guard BEFORE DELETE ON people_training_sessions
                BEGIN SELECT RAISE(ABORT, 'participation identity and sessions are immutable'); END;
                CREATE TRIGGER people_training_participants_UPDATE_guard BEFORE UPDATE ON people_training_participants
                WHEN OLD.tenant_id IS NOT NEW.tenant_id
                    OR OLD.company_entity_id IS NOT NEW.company_entity_id
                    OR OLD.event_id IS NOT NEW.event_id
                    OR OLD.provider_id IS NOT NEW.provider_id
                    OR OLD.employee_subject_id IS NOT NEW.employee_subject_id
                    OR OLD.workforce_observed_at IS NOT NEW.workforce_observed_at
                    OR OLD.created_at IS NOT NEW.created_at
                BEGIN SELECT RAISE(ABORT, 'participation identity and sessions are immutable'); END;
                CREATE TRIGGER people_training_participants_DELETE_guard BEFORE DELETE ON people_training_participants
                BEGIN SELECT RAISE(ABORT, 'participation identity and sessions are immutable'); END;
                CREATE TRIGGER people_training_participation_facts_UPDATE_guard BEFORE UPDATE ON people_training_participation_facts
                WHEN OLD.confirmed_at IS NOT NULL
                BEGIN SELECT RAISE(ABORT, 'confirmed participation facts are immutable'); END;
                CREATE TRIGGER people_training_participation_facts_DELETE_guard BEFORE DELETE ON people_training_participation_facts
                WHEN OLD.confirmed_at IS NOT NULL
                BEGIN SELECT RAISE(ABORT, 'confirmed participation facts are immutable'); END;
                SQL);
        }
    }
};
