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

    private const TABLE = 'people_progression_policies';

    public function up(): void
    {
        Schema::create(self::TABLE, function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            // The owning company: a platform company id, the same axis every
            // other People company-owned table pins (plan 0001).
            $table->unsignedBigInteger('company_entity_id');
            $table->string('policy_id', 80);
            $table->string('version', 40);
            $table->string('status', 16);
            $table->date('effective_from');
            $table->json('rules');
            $table->timestamp('published_at')->nullable();
            $table->unsignedBigInteger('published_by_user_id')->nullable();
            $table->timestamp('superseded_at')->nullable();
            $table->timestamps();

            $table->index('tenant_id', 'ppp_tenant_idx');
            $table->unique(['id', 'tenant_id'], 'ppp_id_tenant_uq');
            $table->unique(['tenant_id', 'company_entity_id', 'policy_id', 'version'], 'ppp_policy_version_uq');
            $table->index(['tenant_id', 'company_entity_id', 'status', 'effective_from'], 'ppp_company_status_effective_idx');
            $table->foreign('tenant_id', 'ppp_tenant_fk')
                ->references('id')->on('tenants')->restrictOnDelete();
            $table->foreign(['company_entity_id', 'tenant_id'], 'ppp_company_tenant_fk')
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
