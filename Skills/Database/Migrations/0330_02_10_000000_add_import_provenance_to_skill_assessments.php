<?php

use App\Base\Database\Concerns\IncubatingSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Import provenance on assessments (blb-people#390 / [0008-d]).
 *
 * `source` names the channel an assessment arrived through (the matrix leaves
 * it null; the assessment-log importer writes `workbook:04-assessment-log`)
 * and `source_reference` is the channel's idempotency key: for a workbook,
 * `<file sha256>:<row>`. A second apply of the same bytes finds its rows here
 * and creates nothing. Alters the incubating assessment table.
 */
return new class extends Migration
{
    use IncubatingSchema;

    public function up(): void
    {
        Schema::table('people_connector_skill_assessments', function (Blueprint $table): void {
            $table->string('source', 64)->nullable()->after('supersedes_assessment_id');
            $table->string('source_reference', 120)->nullable()->after('source');
            $table->index(['tenant_id', 'company_entity_id', 'source_reference'], 'pcs_assess_source_reference_idx');
        });
    }

    public function down(): void
    {
        Schema::table('people_connector_skill_assessments', function (Blueprint $table): void {
            $table->dropIndex('pcs_assess_source_reference_idx');
            $table->dropColumn(['source', 'source_reference']);
        });
    }
};
