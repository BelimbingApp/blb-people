<?php

use App\Base\Database\Concerns\IncubatingSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    use IncubatingSchema;

    /** @var array<string, string> */
    private const APPEND_ONLY_TABLES = [
        'people_training_pilot_signoffs' => 'ptps',
        'people_training_evaluation_followup_audits' => 'ptefa',
        'people_training_passport_document_audits' => 'ptpda',
        'people_training_migration_mapping_signoffs' => 'ptmms',
        'people_training_migration_field_mappings' => 'ptmfm',
        'people_training_department_budget_audits' => 'ptdba',
        'people_training_migration_writer_windows' => 'ptmww',
    ];

    public function up(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            $this->installPostgresGuards();

            return;
        }

        $this->installSqliteGuards();
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            foreach (self::APPEND_ONLY_TABLES as $table => $prefix) {
                DB::unprepared("DROP TRIGGER IF EXISTS {$prefix}_append_only ON {$table}");
            }

            DB::unprepared('DROP FUNCTION IF EXISTS people_training_append_only_guard()');
            DB::unprepared('DROP TRIGGER IF EXISTS ptpi_released_immutable ON people_training_plan_items');
            DB::unprepared('DROP FUNCTION IF EXISTS ptpi_released_guard()');

            return;
        }

        foreach (self::APPEND_ONLY_TABLES as $prefix) {
            DB::unprepared("DROP TRIGGER IF EXISTS {$prefix}_update_guard");
            DB::unprepared("DROP TRIGGER IF EXISTS {$prefix}_delete_guard");
        }

        DB::unprepared('DROP TRIGGER IF EXISTS ptpi_released_update_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS ptpi_released_delete_guard');
    }

    private function installPostgresGuards(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION people_training_append_only_guard() RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION '% records are append-only', TG_TABLE_NAME;
            END;
            $$ LANGUAGE plpgsql;
            SQL);

        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS ptps_append_only ON people_training_pilot_signoffs;
            CREATE TRIGGER ptps_append_only BEFORE UPDATE OR DELETE ON people_training_pilot_signoffs
                FOR EACH ROW EXECUTE FUNCTION people_training_append_only_guard();
            DROP TRIGGER IF EXISTS ptefa_append_only ON people_training_evaluation_followup_audits;
            CREATE TRIGGER ptefa_append_only BEFORE UPDATE OR DELETE ON people_training_evaluation_followup_audits
                FOR EACH ROW EXECUTE FUNCTION people_training_append_only_guard();
            DROP TRIGGER IF EXISTS ptpda_append_only ON people_training_passport_document_audits;
            CREATE TRIGGER ptpda_append_only BEFORE UPDATE OR DELETE ON people_training_passport_document_audits
                FOR EACH ROW EXECUTE FUNCTION people_training_append_only_guard();
            DROP TRIGGER IF EXISTS ptmms_append_only ON people_training_migration_mapping_signoffs;
            CREATE TRIGGER ptmms_append_only BEFORE UPDATE OR DELETE ON people_training_migration_mapping_signoffs
                FOR EACH ROW EXECUTE FUNCTION people_training_append_only_guard();
            DROP TRIGGER IF EXISTS ptmfm_append_only ON people_training_migration_field_mappings;
            CREATE TRIGGER ptmfm_append_only BEFORE UPDATE OR DELETE ON people_training_migration_field_mappings
                FOR EACH ROW EXECUTE FUNCTION people_training_append_only_guard();
            DROP TRIGGER IF EXISTS ptdba_append_only ON people_training_department_budget_audits;
            CREATE TRIGGER ptdba_append_only BEFORE UPDATE OR DELETE ON people_training_department_budget_audits
                FOR EACH ROW EXECUTE FUNCTION people_training_append_only_guard();
            DROP TRIGGER IF EXISTS ptmww_append_only ON people_training_migration_writer_windows;
            CREATE TRIGGER ptmww_append_only BEFORE UPDATE OR DELETE ON people_training_migration_writer_windows
                FOR EACH ROW EXECUTE FUNCTION people_training_append_only_guard();
            SQL);

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION ptpi_released_guard() RETURNS trigger AS $$
            BEGIN
                IF EXISTS (
                    SELECT 1
                    FROM people_training_plans
                    WHERE id = OLD.training_plan_id
                      AND tenant_id = OLD.tenant_id
                      AND company_entity_id = OLD.company_entity_id
                      AND status = 'draft'
                ) THEN
                    IF TG_OP = 'DELETE' THEN
                        RETURN OLD;
                    END IF;

                    RETURN NEW;
                END IF;

                RAISE EXCEPTION 'items are immutable after their plan revision is submitted';
            END;
            $$ LANGUAGE plpgsql;

            DROP TRIGGER IF EXISTS ptpi_released_immutable ON people_training_plan_items;
            CREATE TRIGGER ptpi_released_immutable BEFORE UPDATE OR DELETE ON people_training_plan_items
                FOR EACH ROW EXECUTE FUNCTION ptpi_released_guard();
            SQL);
    }

    private function installSqliteGuards(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER ptps_update_guard BEFORE UPDATE ON people_training_pilot_signoffs
            FOR EACH ROW BEGIN SELECT RAISE(ABORT, 'people_training_pilot_signoffs records are append-only'); END;
            CREATE TRIGGER ptps_delete_guard BEFORE DELETE ON people_training_pilot_signoffs
            FOR EACH ROW BEGIN SELECT RAISE(ABORT, 'people_training_pilot_signoffs records are append-only'); END;
            CREATE TRIGGER ptefa_update_guard BEFORE UPDATE ON people_training_evaluation_followup_audits
            FOR EACH ROW BEGIN SELECT RAISE(ABORT, 'people_training_evaluation_followup_audits records are append-only'); END;
            CREATE TRIGGER ptefa_delete_guard BEFORE DELETE ON people_training_evaluation_followup_audits
            FOR EACH ROW BEGIN SELECT RAISE(ABORT, 'people_training_evaluation_followup_audits records are append-only'); END;
            CREATE TRIGGER ptpda_update_guard BEFORE UPDATE ON people_training_passport_document_audits
            FOR EACH ROW BEGIN SELECT RAISE(ABORT, 'people_training_passport_document_audits records are append-only'); END;
            CREATE TRIGGER ptpda_delete_guard BEFORE DELETE ON people_training_passport_document_audits
            FOR EACH ROW BEGIN SELECT RAISE(ABORT, 'people_training_passport_document_audits records are append-only'); END;
            CREATE TRIGGER ptmms_update_guard BEFORE UPDATE ON people_training_migration_mapping_signoffs
            FOR EACH ROW BEGIN SELECT RAISE(ABORT, 'people_training_migration_mapping_signoffs records are append-only'); END;
            CREATE TRIGGER ptmms_delete_guard BEFORE DELETE ON people_training_migration_mapping_signoffs
            FOR EACH ROW BEGIN SELECT RAISE(ABORT, 'people_training_migration_mapping_signoffs records are append-only'); END;
            CREATE TRIGGER ptmfm_update_guard BEFORE UPDATE ON people_training_migration_field_mappings
            FOR EACH ROW BEGIN SELECT RAISE(ABORT, 'people_training_migration_field_mappings records are append-only'); END;
            CREATE TRIGGER ptmfm_delete_guard BEFORE DELETE ON people_training_migration_field_mappings
            FOR EACH ROW BEGIN SELECT RAISE(ABORT, 'people_training_migration_field_mappings records are append-only'); END;
            CREATE TRIGGER ptdba_update_guard BEFORE UPDATE ON people_training_department_budget_audits
            FOR EACH ROW BEGIN SELECT RAISE(ABORT, 'people_training_department_budget_audits records are append-only'); END;
            CREATE TRIGGER ptdba_delete_guard BEFORE DELETE ON people_training_department_budget_audits
            FOR EACH ROW BEGIN SELECT RAISE(ABORT, 'people_training_department_budget_audits records are append-only'); END;
            CREATE TRIGGER ptmww_update_guard BEFORE UPDATE ON people_training_migration_writer_windows
            FOR EACH ROW BEGIN SELECT RAISE(ABORT, 'people_training_migration_writer_windows records are append-only'); END;
            CREATE TRIGGER ptmww_delete_guard BEFORE DELETE ON people_training_migration_writer_windows
            FOR EACH ROW BEGIN SELECT RAISE(ABORT, 'people_training_migration_writer_windows records are append-only'); END;
            SQL);

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER ptpi_released_update_guard
            BEFORE UPDATE ON people_training_plan_items
            FOR EACH ROW WHEN NOT EXISTS (
                SELECT 1 FROM people_training_plans
                WHERE id = OLD.training_plan_id
                  AND tenant_id = OLD.tenant_id
                  AND company_entity_id = OLD.company_entity_id
                  AND status = 'draft'
            )
            BEGIN SELECT RAISE(ABORT, 'items are immutable after their plan revision is submitted'); END;
            SQL);
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER ptpi_released_delete_guard
            BEFORE DELETE ON people_training_plan_items
            FOR EACH ROW WHEN NOT EXISTS (
                SELECT 1 FROM people_training_plans
                WHERE id = OLD.training_plan_id
                  AND tenant_id = OLD.tenant_id
                  AND company_entity_id = OLD.company_entity_id
                  AND status = 'draft'
            )
            BEGIN SELECT RAISE(ABORT, 'items are immutable after their plan revision is submitted'); END;
            SQL);
    }
};
