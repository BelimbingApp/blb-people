<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * HR decision outcome on evidence submissions (0011-b).
 *
 * Confirming or returning a submission records who decided, when, and the
 * note the employee sees. The note lives here for display; the immutable
 * trail lives in people_training_evidence_decisions.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('people_training_evidence_submissions', function (Blueprint $table): void {
            $table->unsignedBigInteger('decided_by_user_id')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->string('decision_note', 2000)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('people_training_evidence_submissions', function (Blueprint $table): void {
            $table->dropColumn(['decided_by_user_id', 'decided_at', 'decision_note']);
        });
    }
};
