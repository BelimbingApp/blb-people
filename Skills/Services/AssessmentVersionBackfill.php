<?php

namespace App\Domains\People\Skills\Services;

use App\Domains\People\Skills\Enums\RequirementProfileStatus;
use Illuminate\Support\Facades\DB;

/**
 * Pin assessments written before the version column existed — as far as the
 * domain allows, which is not all of them.
 *
 * Draft rows only. A non-draft assessment cannot be updated outside
 * AssessmentWorkflowContext, whose authority AssessmentStore alone may issue,
 * because finalized assessments are historical records rather than rows. A
 * migration that reached around that to rewrite them would be weakening a
 * deliberate immutability guard for the convenience of a backfill, so it does
 * not: historical rows keep a null pin, which honestly says "not recorded"
 * rather than asserting a version nobody can verify.
 *
 * That limit is worth stating rather than hiding. The version a historical row
 * was really taken against cannot be recovered from a reference and a number —
 * that irrecoverability is the whole reason this column exists — so a confident
 * guess written onto a finalized record would be the worst of both.
 *
 * Lives here rather than inside the migration so it can be run against a
 * fixture and shown to do what it claims.
 */
final class AssessmentVersionBackfill
{
    public static function run(): int
    {
        $pinned = 0;

        DB::table('people_connector_skill_assessments')
            ->whereNull('requirement_profile_id')
            ->where('status', 'draft')
            ->orderBy('id')
            ->chunkById(500, function ($rows) use (&$pinned): void {
                foreach ($rows as $row) {
                    $profileId = DB::table('people_connector_skill_requirement_profiles')
                        ->where('tenant_id', $row->tenant_id)
                        ->where('company_entity_id', $row->company_entity_id)
                        ->where('code', $row->requirement_reference)
                        ->where('status', RequirementProfileStatus::Published->value)
                        ->orderByDesc('version')
                        ->value('id');

                    if ($profileId === null) {
                        continue;
                    }

                    DB::table('people_connector_skill_assessments')
                        ->where('id', $row->id)
                        ->update(['requirement_profile_id' => $profileId]);
                    $pinned++;
                }
            });

        return $pinned;
    }
}
