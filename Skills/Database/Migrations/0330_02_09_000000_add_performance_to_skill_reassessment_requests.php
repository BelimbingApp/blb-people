<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * HR performance outcome on reassessment requests (0006-c).
 *
 * Performing a request closes it with who performed it, when, and the
 * recorded outcome. The outcome columns stay null while the request is
 * open; the score history itself lives in the assessments table, so this
 * migration only carries the request-side closure.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('people_connector_skill_reassessment_requests', function (Blueprint $table): void {
            $table->unsignedBigInteger('performed_by_user_id')->nullable();
            $table->timestamp('performed_at')->nullable();
            $table->string('outcome', 1000)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('people_connector_skill_reassessment_requests', function (Blueprint $table): void {
            $table->dropColumn(['performed_by_user_id', 'performed_at', 'outcome']);
        });
    }
};
