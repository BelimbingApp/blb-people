<?php

use App\Base\Database\Concerns\RegistersTables;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    use RegistersTables;

    public function up(): void
    {
        Schema::create('people_training_evidence_submissions', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->unsignedBigInteger('company_entity_id');
            $table->unsignedBigInteger('event_id');
            $table->unsignedBigInteger('participant_id');
            $table->text('reflection');
            $table->string('certificate_number', 160)->nullable();
            $table->date('certificate_expires_on')->nullable();
            $table->unsignedBigInteger('document_asset_id');
            $table->string('status', 24)->default('pending');
            $table->unsignedBigInteger('submitted_by_user_id');
            $table->timestamp('submitted_at');
            $table->timestamps();

            $table->unique(['id', 'tenant_id', 'company_entity_id'], 'ptes_owner_uq');
            $table->unique(['tenant_id', 'participant_id'], 'ptes_participant_uq');
            $table->foreign(['company_entity_id', 'tenant_id'], 'ptes_company_fk')
                ->references(['id', 'tenant_id'])->on('companies')->restrictOnDelete();
            $table->foreign(['event_id', 'tenant_id', 'company_entity_id'], 'ptes_event_fk')
                ->references(['id', 'tenant_id', 'company_entity_id'])->on('people_connector_training_events')->restrictOnDelete();
            $table->foreign(['participant_id', 'tenant_id', 'company_entity_id', 'event_id'], 'ptes_participant_fk')
                ->references(['id', 'tenant_id', 'company_entity_id', 'event_id'])->on('people_training_participants')->restrictOnDelete();
            $table->foreign('document_asset_id', 'ptes_document_fk')->references('id')->on('base_media_assets')->restrictOnDelete();
        });

        $this->registerTable('people_training_evidence_submissions');
    }

    public function down(): void
    {
        $this->unregisterTable('people_training_evidence_submissions');
        Schema::dropIfExists('people_training_evidence_submissions');
    }
};
