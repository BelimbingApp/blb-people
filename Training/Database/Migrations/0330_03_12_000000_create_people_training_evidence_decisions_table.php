<?php

use App\Base\Database\Concerns\IncubatingSchema;
use App\Base\Database\Concerns\RegistersTables;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Immutable HR decision trail for evidence submissions (0011-b).
 *
 * One row per confirm/return decision naming the HR user, mirroring the
 * event audit events. The submission row carries the current state; this
 * table carries the history.
 */
return new class extends Migration
{
    use IncubatingSchema;
    use RegistersTables;

    public function up(): void
    {
        Schema::create('people_training_evidence_decisions', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->unsignedBigInteger('company_entity_id');
            $table->unsignedBigInteger('submission_id');
            $table->unsignedBigInteger('event_id');
            $table->unsignedBigInteger('participant_id');
            $table->string('decision', 24);
            $table->string('note', 2000)->nullable();
            $table->unsignedBigInteger('decided_by_user_id');
            $table->timestamp('decided_at');
            $table->timestamps();

            $table->unique(['id', 'tenant_id', 'company_entity_id'], 'pted_owner_uq');
            $table->foreign(['company_entity_id', 'tenant_id'], 'pted_company_fk')
                ->references(['id', 'tenant_id'])->on('companies')->restrictOnDelete();
            $table->foreign(['submission_id', 'tenant_id', 'company_entity_id'], 'pted_submission_fk')
                ->references(['id', 'tenant_id', 'company_entity_id'])->on('people_training_evidence_submissions')->restrictOnDelete();
        });

        $this->registerTable('people_training_evidence_decisions');
    }

    public function down(): void
    {
        $this->unregisterTable('people_training_evidence_decisions');
        Schema::dropIfExists('people_training_evidence_decisions');
    }
};
