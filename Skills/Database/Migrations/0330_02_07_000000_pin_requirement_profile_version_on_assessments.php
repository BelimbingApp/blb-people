<?php

use App\Base\Database\Concerns\ReplaysAfterIncubatingSchema;
use App\Domains\People\Skills\Services\AssessmentVersionBackfill;
use Illuminate\Database\Migrations\Migration;

/**
 * Pin each assessment to the requirement profile *version* it was taken
 * against (blb-people#182 / [0002-b]).
 *
 * The incubating assessment create now ships `requirement_profile_id`. This
 * migration only backfills existing rows. It declares
 * ReplaysAfterIncubatingSchema so a local rebuild of the incubating
 * assessment tables recomputes that backfill without authorizing schema
 * mutation.
 */
return new class extends Migration
{
    use ReplaysAfterIncubatingSchema;

    public function up(): void
    {
        AssessmentVersionBackfill::run();
    }

    public function down(): void
    {
        // Backfill-only: clearing pinned ids would discard provenance that
        // may also have been written by application code after migrate.
    }
};
