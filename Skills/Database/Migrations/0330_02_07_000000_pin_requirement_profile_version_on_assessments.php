<?php

use App\Domains\People\Skills\Services\AssessmentVersionBackfill;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pin each assessment to the requirement profile *version* it was taken
 * against (blb-people#182 / [0002-b]).
 *
 * The row already carried requirement_reference and requirement_version, which
 * name a version but do not identify a row: nothing tied them to a profile that
 * exists, was published, or belongs to this company. The contract in
 * docs/contracts/requirement-versioning.md wants the particular version
 * retained, and a code plus a number is not that.
 *
 * A separate migration rather than an edit to the create file, because the
 * backfill this lane requires has no meaning until there are rows to backfill.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('people_connector_skill_assessments', function (Blueprint $table): void {
            $table->unsignedBigInteger('requirement_profile_id')->nullable()->after('requirement_version');
            $table->index(['tenant_id', 'requirement_profile_id'], 'pcs_assessment_req_profile_idx');
        });

        AssessmentVersionBackfill::run();
    }

    public function down(): void
    {
        Schema::table('people_connector_skill_assessments', function (Blueprint $table): void {
            $table->dropIndex('pcs_assessment_req_profile_idx');
            $table->dropColumn('requirement_profile_id');
        });
    }
};
