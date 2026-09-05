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

    private const TABLE = 'people_job_descriptions';

    public function up(): void
    {
        Schema::create(self::TABLE, function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('company_entity_id');
            $table->string('reference', 80);
            $table->string('position_stable_id');
            $table->unsignedInteger('position_version');
            $table->unsignedInteger('version');
            $table->string('status', 16);
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->text('purpose');
            $table->json('responsibilities');
            $table->json('duties');
            $table->text('authority');
            $table->json('qualifications');
            $table->json('competency_links');
            $table->timestamp('published_at')->nullable();
            $table->unsignedBigInteger('published_by_user_id')->nullable();
            $table->timestamp('superseded_at')->nullable();
            $table->timestamps();

            $table->index('tenant_id', 'pjd_tenant_idx');
            $table->unique(['id', 'tenant_id'], 'pjd_id_tenant_uq');
            $table->unique(['tenant_id', 'company_entity_id', 'reference', 'version'], 'pjd_reference_version_uq');
            $table->index(['tenant_id', 'company_entity_id', 'position_stable_id', 'position_version'], 'pjd_position_version_idx');
            $table->foreign('tenant_id', 'pjd_tenant_fk')->references('id')->on('tenants')->restrictOnDelete();
            $table->foreign(['company_entity_id', 'tenant_id'], 'pjd_company_tenant_fk')
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
