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

    private const TABLE = 'people_kpi_records';

    public function up(): void
    {
        Schema::create(self::TABLE, function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('company_entity_id');
            $table->string('kpi_key', 80);
            $table->string('owner_subject_type', 32);
            $table->string('owner_subject_id', 128);
            $table->text('measure');
            $table->text('target');
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
        });

        $this->registerTable(self::TABLE);
    }

    public function down(): void
    {
        $this->unregisterTable(self::TABLE);
        Schema::dropIfExists(self::TABLE);
    }
};
