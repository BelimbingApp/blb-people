<?php

use App\Base\Database\Concerns\IncubatingSchema;
use App\Base\Database\Concerns\RegistersTables;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    use IncubatingSchema;
    use RegistersTables;

    private const DEFINITIONS = 'people_kpi_definitions';

    private const ASSIGNMENTS = 'people_kpi_records';

    public function up(): void
    {
        Schema::create(self::DEFINITIONS, function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('company_entity_id');
            $table->string('kpi_key', 80);
            $table->unsignedInteger('version');
            $table->string('name');
            $table->text('purpose');
            $table->string('steward_subject_type', 32);
            $table->string('steward_subject_id', 128);
            $table->string('unit', 40);
            $table->text('measure');
            $table->string('source_reference');
            $table->string('direction', 24);
            $table->text('rubric')->nullable();
            $table->string('calculation_version', 40);
            $table->unsignedTinyInteger('precision')->default(0);
            $table->text('interpretation');
            $table->timestamps();

            $table->index('tenant_id', 'pkd_tenant_idx');
            $table->unique(['id', 'tenant_id'], 'pkd_id_tenant_uq');
            $table->unique(['tenant_id', 'company_entity_id', 'kpi_key', 'version'], 'pkd_key_version_uq');
            $table->foreign('tenant_id', 'pkd_tenant_fk')->references('id')->on('tenants')->restrictOnDelete();
            $table->foreign(['company_entity_id', 'tenant_id'], 'pkd_company_tenant_fk')
                ->references(['id', 'tenant_id'])->on('companies')->restrictOnDelete();
        });

        Schema::create(self::ASSIGNMENTS, function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('company_entity_id');
            $table->unsignedBigInteger('kpi_definition_id');
            $table->unsignedInteger('kpi_definition_version');
            $table->string('owner_subject_type', 32);
            $table->string('owner_subject_id', 128);
            $table->text('target');
            $table->json('attributed_employee_subject_ids');
            $table->unsignedInteger('target_version')->default(1);
            $table->unsignedBigInteger('supersedes_assignment_id')->nullable();
            $table->text('amendment_reason')->nullable();
            $table->date('effective_from');
            $table->date('period_start');
            $table->date('period_end');
            $table->json('evidence_references');
            $table->text('review_outcome')->nullable();
            $table->boolean('confidential')->default(false);
            $table->string('status', 16);
            $table->unsignedBigInteger('proposed_by_user_id');
            $table->unsignedBigInteger('reviewed_by_user_id')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->unsignedBigInteger('published_by_user_id')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();

            $table->index('tenant_id', 'pkr_tenant_idx');
            $table->unique(['id', 'tenant_id'], 'pkr_id_tenant_uq');
            $table->index(
                ['tenant_id', 'company_entity_id', 'owner_subject_type', 'owner_subject_id', 'period_start'],
                'pkr_owner_period_idx',
            );
            $table->foreign('tenant_id', 'pkr_tenant_fk')
                ->references('id')->on('tenants')->restrictOnDelete();
            $table->foreign(['company_entity_id', 'tenant_id'], 'pkr_company_tenant_fk')
                ->references(['id', 'tenant_id'])->on('companies')->restrictOnDelete();
            $table->foreign(['kpi_definition_id', 'tenant_id'], 'pkr_definition_tenant_fk')
                ->references(['id', 'tenant_id'])->on(self::DEFINITIONS)->restrictOnDelete();
            $table->foreign(['supersedes_assignment_id', 'tenant_id'], 'pkr_supersedes_tenant_fk')
                ->references(['id', 'tenant_id'])->on(self::ASSIGNMENTS)->restrictOnDelete();
        });

        $this->registerTable(self::DEFINITIONS);
        $this->registerTable(self::ASSIGNMENTS);
    }

    public function down(): void
    {
        $this->unregisterTable(self::ASSIGNMENTS);
        $this->unregisterTable(self::DEFINITIONS);
        Schema::dropIfExists(self::ASSIGNMENTS);
        Schema::dropIfExists(self::DEFINITIONS);
    }
};
