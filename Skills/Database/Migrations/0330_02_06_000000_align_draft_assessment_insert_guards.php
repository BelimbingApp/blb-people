<?php

use App\Domains\People\Skills\Exceptions\InvalidAssessmentException;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $invalidDraftExists = DB::table('people_connector_skill_assessments')
            ->where('status', 'draft')
            ->where(function (Builder $query): void {
                $query->where('hod_verification', '<>', 'pending')
                    ->orWhereNotNull('finalized_at')
                    ->orWhereNotNull('finalized_by_user_id');
            })
            ->exists();

        if ($invalidDraftExists) {
            throw new InvalidAssessmentException(
                'Draft assessments contain finalization or HOD decisions. Reconcile the source records before retrying this migration; no assessment history was changed.',
            );
        }

        // PostgreSQL already enforces this predicate in pcs_assessment_workflow_guard.
        if (DB::connection()->getDriverName() === 'sqlite') {
            DB::unprepared(<<<'SQL'
                CREATE TRIGGER IF NOT EXISTS pcs_assessment_draft_insert_guard
                BEFORE INSERT ON people_connector_skill_assessments
                FOR EACH ROW
                WHEN NEW.status = 'draft' AND (
                    NEW.hod_verification <> 'pending'
                    OR NEW.finalized_at IS NOT NULL
                    OR NEW.finalized_by_user_id IS NOT NULL
                )
                BEGIN
                    SELECT RAISE(ABORT, 'draft assessments cannot carry finalization or HOD decisions');
                END;
                SQL);
        }
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'sqlite') {
            DB::unprepared('DROP TRIGGER IF EXISTS pcs_assessment_draft_insert_guard');
        }
    }
};
