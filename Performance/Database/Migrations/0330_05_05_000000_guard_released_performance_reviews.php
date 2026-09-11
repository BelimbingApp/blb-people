<?php

use App\Base\Database\Concerns\IncubatingSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    use IncubatingSchema;

    public function up(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared(<<<'SQL'
                CREATE OR REPLACE FUNCTION ppr_released_guard() RETURNS trigger AS $$
                BEGIN
                    IF TG_OP = 'DELETE' THEN
                        IF OLD.status <> 'draft' THEN
                            RAISE EXCEPTION 'a released performance review cannot be deleted';
                        END IF;

                        RETURN OLD;
                    END IF;

                    IF OLD.status = 'draft' THEN
                        RETURN NEW;
                    END IF;

                    IF OLD.status = 'finalized'
                        AND NEW.status = 'superseded'
                        AND NEW.superseded_at IS NOT NULL
                        AND NEW.id IS NOT DISTINCT FROM OLD.id
                        AND NEW.tenant_id IS NOT DISTINCT FROM OLD.tenant_id
                        AND NEW.company_entity_id IS NOT DISTINCT FROM OLD.company_entity_id
                        AND NEW.employee_entity_id IS NOT DISTINCT FROM OLD.employee_entity_id
                        AND NEW.review_key IS NOT DISTINCT FROM OLD.review_key
                        AND NEW.version IS NOT DISTINCT FROM OLD.version
                        AND NEW.period_start IS NOT DISTINCT FROM OLD.period_start
                        AND NEW.period_end IS NOT DISTINCT FROM OLD.period_end
                        AND NEW.cutoff_at IS NOT DISTINCT FROM OLD.cutoff_at
                        AND NEW.outcome IS NOT DISTINCT FROM OLD.outcome
                        AND NEW.rationale IS NOT DISTINCT FROM OLD.rationale
                        AND NEW.reviewer_user_id IS NOT DISTINCT FROM OLD.reviewer_user_id
                        AND NEW.finalized_at IS NOT DISTINCT FROM OLD.finalized_at
                        AND NEW.supersedes_review_id IS NOT DISTINCT FROM OLD.supersedes_review_id
                        AND NEW.correction_reason IS NOT DISTINCT FROM OLD.correction_reason
                        AND NEW.created_at IS NOT DISTINCT FROM OLD.created_at
                    THEN
                        RETURN NEW;
                    END IF;

                    RAISE EXCEPTION 'a released performance review cannot be modified';
                END;
                $$ LANGUAGE plpgsql;

                DROP TRIGGER IF EXISTS ppr_released_immutable ON people_performance_reviews;
                CREATE TRIGGER ppr_released_immutable
                    BEFORE UPDATE OR DELETE ON people_performance_reviews
                    FOR EACH ROW EXECUTE FUNCTION ppr_released_guard();
                SQL);

            return;
        }

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER ppr_released_update_guard
            BEFORE UPDATE ON people_performance_reviews
            FOR EACH ROW
            WHEN OLD.status <> 'draft' AND NOT (
                OLD.status = 'finalized'
                AND NEW.status = 'superseded'
                AND NEW.superseded_at IS NOT NULL
                AND NEW.id IS OLD.id
                AND NEW.tenant_id IS OLD.tenant_id
                AND NEW.company_entity_id IS OLD.company_entity_id
                AND NEW.employee_entity_id IS OLD.employee_entity_id
                AND NEW.review_key IS OLD.review_key
                AND NEW.version IS OLD.version
                AND NEW.period_start IS OLD.period_start
                AND NEW.period_end IS OLD.period_end
                AND NEW.cutoff_at IS OLD.cutoff_at
                AND NEW.outcome IS OLD.outcome
                AND NEW.rationale IS OLD.rationale
                AND NEW.reviewer_user_id IS OLD.reviewer_user_id
                AND NEW.finalized_at IS OLD.finalized_at
                AND NEW.supersedes_review_id IS OLD.supersedes_review_id
                AND NEW.correction_reason IS OLD.correction_reason
                AND NEW.created_at IS OLD.created_at
            )
            BEGIN SELECT RAISE(ABORT, 'a released performance review cannot be modified'); END;

            CREATE TRIGGER ppr_released_delete_guard
            BEFORE DELETE ON people_performance_reviews
            FOR EACH ROW WHEN OLD.status <> 'draft'
            BEGIN SELECT RAISE(ABORT, 'a released performance review cannot be deleted'); END;
            SQL);
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared(<<<'SQL'
                DROP TRIGGER IF EXISTS ppr_released_immutable ON people_performance_reviews;
                DROP FUNCTION IF EXISTS ppr_released_guard();
                SQL);

            return;
        }

        DB::unprepared('DROP TRIGGER IF EXISTS ppr_released_update_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS ppr_released_delete_guard');
    }
};
