<?php

use App\Base\Database\Concerns\IncubatingSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Provenance guard for assisted (paper) evaluation entry (blb-people#332 / [0012-f]).
 *
 * docs/contracts/training-evaluation.md: paper or assisted entry "retains the
 * employee subject, actual entering actor, source and provenance". A row that
 * says the answers came from paper but names nobody who keyed them in has no
 * provenance, so the database refuses it on any write path — including raw
 * DB::table() writes that step around the store.
 */
return new class extends Migration
{
    use IncubatingSchema;

    public function up(): void
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'pgsql') {
            DB::unprepared(<<<'SQL'
                CREATE OR REPLACE FUNCTION pte_assisted_entry_actor_guard() RETURNS trigger AS $$
                BEGIN
                    IF NEW.entry_source = 'assisted_paper' AND NEW.submitted_by_user_id IS NULL THEN
                        RAISE EXCEPTION 'assisted paper evaluation entry requires the entering actor';
                    END IF;
                    RETURN NEW;
                END;
                $$ LANGUAGE plpgsql;

                CREATE TRIGGER pte_assisted_entry_actor_guard_trigger
                    BEFORE INSERT OR UPDATE ON people_training_evaluations
                    FOR EACH ROW EXECUTE FUNCTION pte_assisted_entry_actor_guard();
            SQL);
        } elseif ($driver === 'sqlite') {
            // One statement per trigger, plain string concatenation: the
            // schema-drift verifier reads migration source statically.
            DB::statement(
                'CREATE TRIGGER pte_assisted_entry_actor_insert_guard BEFORE INSERT ON people_training_evaluations'
                ." WHEN NEW.entry_source = 'assisted_paper' AND NEW.submitted_by_user_id IS NULL"
                ." BEGIN SELECT RAISE(ABORT, 'assisted paper evaluation entry requires the entering actor'); END",
            );

            DB::statement(
                'CREATE TRIGGER pte_assisted_entry_actor_update_guard BEFORE UPDATE ON people_training_evaluations'
                ." WHEN NEW.entry_source = 'assisted_paper' AND NEW.submitted_by_user_id IS NULL"
                ." BEGIN SELECT RAISE(ABORT, 'assisted paper evaluation entry requires the entering actor'); END",
            );
        }
    }

    public function down(): void
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'pgsql') {
            DB::unprepared(<<<'SQL'
                DROP TRIGGER IF EXISTS pte_assisted_entry_actor_guard_trigger ON people_training_evaluations;
                DROP FUNCTION IF EXISTS pte_assisted_entry_actor_guard();
            SQL);
        } elseif ($driver === 'sqlite') {
            DB::statement('DROP TRIGGER IF EXISTS pte_assisted_entry_actor_insert_guard');
            DB::statement('DROP TRIGGER IF EXISTS pte_assisted_entry_actor_update_guard');
        }
    }
};
