<?php

use App\Base\Database\Concerns\IncubatingSchema;
use App\Base\Database\Concerns\RegistersTables;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Printable training passport (0014-a).
 *
 * A document row names one generated PDF: the platform media asset that
 * holds the bytes, the employee it describes, who generated it and when it
 * stops being downloadable (30 days); purging drops the bytes and clears the
 * asset pointer while the row and its audits remain. The audit table is append-only and
 * records every generation and download with the acting user, mirroring the
 * event audit events: the document says what was produced, the audit says
 * who touched it.
 */
return new class extends Migration
{
    use IncubatingSchema;
    use RegistersTables;

    public function up(): void
    {
        Schema::create('people_training_passport_documents', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->unsignedBigInteger('company_entity_id');
            $table->unsignedBigInteger('employee_entity_id');
            $table->unsignedBigInteger('media_asset_id')->nullable();
            $table->string('template_version', 80);
            $table->string('data_version', 64);
            $table->string('sha256', 64);
            $table->unsignedBigInteger('bytes');
            $table->unsignedBigInteger('generated_by_user_id');
            $table->timestamp('generated_at');
            $table->timestamp('expires_at');
            $table->timestamps();

            $table->unique(['id', 'tenant_id', 'company_entity_id'], 'ptpd_owner_uq');
            $table->index(['tenant_id', 'company_entity_id', 'employee_entity_id', 'expires_at'], 'ptpd_employee_idx');
            $table->foreign(['company_entity_id', 'tenant_id'], 'ptpd_company_fk')
                ->references(['id', 'tenant_id'])->on('companies')->restrictOnDelete();
            $table->foreign('media_asset_id', 'ptpd_media_asset_fk')
                ->references('id')->on('base_media_assets')->restrictOnDelete();
        });

        Schema::create('people_training_passport_document_audits', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->unsignedBigInteger('company_entity_id');
            $table->unsignedBigInteger('document_id');
            $table->unsignedBigInteger('employee_entity_id');
            $table->string('event_type', 24);
            $table->unsignedBigInteger('actor_user_id');
            $table->json('metadata')->nullable();
            $table->timestamp('occurred_at');

            $table->unique(['id', 'tenant_id', 'company_entity_id'], 'ptpda_owner_uq');
            $table->index(['tenant_id', 'company_entity_id', 'document_id', 'occurred_at'], 'ptpda_document_idx');
            $table->foreign(['company_entity_id', 'tenant_id'], 'ptpda_company_fk')
                ->references(['id', 'tenant_id'])->on('companies')->restrictOnDelete();
            $table->foreign(['document_id', 'tenant_id', 'company_entity_id'], 'ptpda_document_fk')
                ->references(['id', 'tenant_id', 'company_entity_id'])->on('people_training_passport_documents')->restrictOnDelete();
        });

        $this->registerTable('people_training_passport_documents');
        $this->registerTable('people_training_passport_document_audits');
    }

    public function down(): void
    {
        $this->unregisterTable('people_training_passport_document_audits');
        $this->unregisterTable('people_training_passport_documents');
        Schema::dropIfExists('people_training_passport_document_audits');
        Schema::dropIfExists('people_training_passport_documents');
    }
};
