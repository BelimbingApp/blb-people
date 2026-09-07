<?php

use App\Base\Database\Concerns\IncubatingSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The development action that a "partially effective" or "not yet effective"
 * review owes somebody (0013-f).
 *
 * Before this column the closure rule in `docs/contracts/training-effectiveness.md`
 * had nowhere to land: `further_action` is free text, so a review that found
 * the training had not worked ended in a sentence with no owner, no due date
 * and no reassessment. The link points at the Skills development action that
 * carries all three.
 *
 * The development actions table's owner key is `(id, tenant_id)`, exactly as
 * the assessments table's is, so the foreign key can only prove the tenant.
 * The company match is the store's job — and, because a store is never the
 * only write path, a trigger's too. On SQLite a composite foreign key cannot
 * be added to a table that already exists, so there the trigger is the whole
 * guard; on PostgreSQL it sits beside the key and adds the company.
 *
 * Declares IncubatingSchema because it alters people_training_effectiveness_reviews,
 * which 0330_03_07 creates as incubating. A stable forward onto an incubating
 * table is what IncubatingSchemaConflictException refuses: rebuilding the
 * create alone would drop this column while the ledger still claimed it was
 * applied.
 */
return new class extends Migration
{
    use IncubatingSchema;

    public function up(): void
    {
        Schema::table('people_training_effectiveness_reviews', function (Blueprint $table): void {
            $table->unsignedBigInteger('development_action_id')->nullable()->after('further_action');
            $table->index(['tenant_id', 'company_entity_id', 'development_action_id'], 'pter_dev_action_idx');
        });

        if (DB::connection()->getDriverName() === 'pgsql') {
            Schema::table('people_training_effectiveness_reviews', function (Blueprint $table): void {
                $table->foreign(['development_action_id', 'tenant_id'], 'pter_dev_action_fk')
                    ->references(['id', 'tenant_id'])->on('people_connector_skill_development_actions')
                    ->cascadeOnUpdate()->restrictOnDelete();
            });
            DB::unprepared(<<<'SQL'
                CREATE FUNCTION pter_dev_action_company_guard() RETURNS trigger AS $$
                BEGIN
                    IF NEW.development_action_id IS NOT NULL AND NOT EXISTS (
                        SELECT 1 FROM people_connector_skill_development_actions a
                        WHERE a.id = NEW.development_action_id
                          AND a.tenant_id = NEW.tenant_id
                          AND a.company_entity_id = NEW.company_entity_id
                    ) THEN
                        RAISE EXCEPTION 'a review links only a development action of its own tenant and company';
                    END IF;
                    RETURN NEW;
                END;
                $$ LANGUAGE plpgsql;
                CREATE TRIGGER pter_dev_action_company_guard
                BEFORE INSERT OR UPDATE ON people_training_effectiveness_reviews
                FOR EACH ROW EXECUTE FUNCTION pter_dev_action_company_guard();
                SQL);
        } elseif (DB::connection()->getDriverName() === 'sqlite') {
            DB::unprepared(<<<'SQL'
                CREATE TRIGGER pter_dev_action_owner_insert_guard
                BEFORE INSERT ON people_training_effectiveness_reviews
                WHEN NEW.development_action_id IS NOT NULL AND NOT EXISTS (
                    SELECT 1 FROM people_connector_skill_development_actions a
                    WHERE a.id = NEW.development_action_id AND a.tenant_id = NEW.tenant_id
                      AND a.company_entity_id = NEW.company_entity_id)
                BEGIN SELECT RAISE(ABORT, 'a review links only a development action of its own tenant and company'); END;
                CREATE TRIGGER pter_dev_action_owner_update_guard
                BEFORE UPDATE ON people_training_effectiveness_reviews
                WHEN NEW.development_action_id IS NOT NULL AND NOT EXISTS (
                    SELECT 1 FROM people_connector_skill_development_actions a
                    WHERE a.id = NEW.development_action_id AND a.tenant_id = NEW.tenant_id
                      AND a.company_entity_id = NEW.company_entity_id)
                BEGIN SELECT RAISE(ABORT, 'a review links only a development action of its own tenant and company'); END;
                SQL);
        }
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::unprepared('DROP TRIGGER IF EXISTS pter_dev_action_company_guard ON people_training_effectiveness_reviews');
            DB::unprepared('DROP FUNCTION IF EXISTS pter_dev_action_company_guard()');
            Schema::table('people_training_effectiveness_reviews', function (Blueprint $table): void {
                $table->dropForeign('pter_dev_action_fk');
            });
        } elseif (DB::connection()->getDriverName() === 'sqlite') {
            foreach (['insert', 'update'] as $event) {
                DB::unprepared("DROP TRIGGER IF EXISTS pter_dev_action_owner_{$event}_guard");
            }
        }
        Schema::table('people_training_effectiveness_reviews', function (Blueprint $table): void {
            $table->dropIndex('pter_dev_action_idx');
            $table->dropColumn('development_action_id');
        });
    }
};
