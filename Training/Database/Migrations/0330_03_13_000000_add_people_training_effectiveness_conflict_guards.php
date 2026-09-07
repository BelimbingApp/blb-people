<?php

use App\Base\Database\Concerns\IncubatingSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Database backstop for the first conflict-of-interest rule in
 * `docs/contracts/training-effectiveness.md` §"Conflicts of interest": the
 * reviewer named on an effectiveness review must not be the participant being
 * reviewed.
 *
 * TrainingEffectivenessStore refuses this on `openStage()`, but the store is
 * not the only write path — a raw `DB::table()->insert()`, a data fix or an
 * importer steps around it. Following the trigger pattern of migration
 * 0330_03_03 (participation immutability), the pair is compared here too, on
 * insert and on update, joining through `training_participant_id` to the
 * participant's `employee_subject_id`.
 *
 * The comparison is textual because the two columns are different types by
 * design: `reviewer_employee_entity_id` is a platform `employees.id`, while
 * `employee_subject_id` is the selected provider's stable id (a string). For
 * the native provider those are the same value, which is exactly the case this
 * guard has to catch; the store applies the identical comparison, so the two
 * layers agree on what counts as a conflict.
 */
return new class extends Migration
{
    use IncubatingSchema;

    public function up(): void
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'pgsql') {
            $this->createPostgresGuard();
        } elseif ($driver === 'sqlite') {
            $this->createSqliteGuards();
        }
    }

    public function down(): void
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'pgsql') {
            DB::unprepared(<<<'SQL'
                DROP TRIGGER IF EXISTS pter_reviewer_conflict_guard_trigger ON people_training_effectiveness_reviews;
                DROP FUNCTION IF EXISTS pter_reviewer_conflict_guard();
            SQL);
        } elseif ($driver === 'sqlite') {
            foreach (['pter_reviewer_conflict_insert_guard', 'pter_reviewer_conflict_update_guard'] as $trigger) {
                DB::statement('DROP TRIGGER IF EXISTS '.$trigger);
            }
        }
    }

    private function createPostgresGuard(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION pter_reviewer_conflict_guard() RETURNS trigger AS $$
            DECLARE
                subject text;
            BEGIN
                SELECT employee_subject_id INTO subject
                    FROM people_training_participants
                    WHERE id = NEW.training_participant_id
                      AND tenant_id = NEW.tenant_id;
                IF subject IS NOT NULL AND subject = NEW.reviewer_employee_entity_id::text THEN
                    RAISE EXCEPTION 'an effectiveness reviewer cannot be the reviewed participant';
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER pter_reviewer_conflict_guard_trigger
                BEFORE INSERT OR UPDATE ON people_training_effectiveness_reviews
                FOR EACH ROW EXECUTE FUNCTION pter_reviewer_conflict_guard();
        SQL);
    }

    private function createSqliteGuards(): void
    {
        // One statement per trigger, built from plain string literals: the
        // schema-drift verifier reads migration source statically (see the
        // note in 0330_03_02 createSqliteGuards).
        DB::statement(
            'CREATE TRIGGER pter_reviewer_conflict_insert_guard'
            .' BEFORE INSERT ON people_training_effectiveness_reviews'
            .' WHEN (SELECT employee_subject_id FROM people_training_participants'
            .' WHERE id = NEW.training_participant_id AND tenant_id = NEW.tenant_id)'
            .' = CAST(NEW.reviewer_employee_entity_id AS TEXT)'
            ." BEGIN SELECT RAISE(ABORT, 'an effectiveness reviewer cannot be the reviewed participant'); END",
        );

        DB::statement(
            'CREATE TRIGGER pter_reviewer_conflict_update_guard'
            .' BEFORE UPDATE ON people_training_effectiveness_reviews'
            .' WHEN (SELECT employee_subject_id FROM people_training_participants'
            .' WHERE id = NEW.training_participant_id AND tenant_id = NEW.tenant_id)'
            .' = CAST(NEW.reviewer_employee_entity_id AS TEXT)'
            ." BEGIN SELECT RAISE(ABORT, 'an effectiveness reviewer cannot be the reviewed participant'); END",
        );
    }
};
