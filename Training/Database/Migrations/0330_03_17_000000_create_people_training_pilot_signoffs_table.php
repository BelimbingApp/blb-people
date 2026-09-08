<?php

use App\Base\Database\Concerns\IncubatingSchema;
use App\Base\Database\Concerns\RegistersTables;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Department pilot readiness sign-offs (blb-people#399 / [0015-c]).
 *
 * Append-only ledger of HOD and HR signatures against a readiness snapshot.
 * One HOD sign-off per company+unit (unique key); HR signs after the HOD.
 */
return new class extends Migration
{
    use IncubatingSchema;
    use RegistersTables;

    private const TABLE = 'people_training_pilot_signoffs';

    public function up(): void
    {
        Schema::create(self::TABLE, function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->unsignedBigInteger('company_entity_id');
            $table->timestamps();
            $table->unique(['id', 'tenant_id', 'company_entity_id'], 'ptps_owner_uq');
            $table->foreign(['company_entity_id', 'tenant_id'], 'ptps_company_fk')
                ->references(['id', 'tenant_id'])->on('companies')->restrictOnDelete();

            $table->unsignedBigInteger('organization_unit_entity_id');
            $table->string('role', 8);
            $table->unsignedBigInteger('signed_by');
            $table->timestamp('signed_at');
            $table->json('readiness_snapshot');
            $table->text('note')->nullable();

            $table->unique(
                ['tenant_id', 'company_entity_id', 'organization_unit_entity_id', 'role'],
                'ptps_unit_role_uq',
            );
            $table->index(
                ['tenant_id', 'company_entity_id', 'organization_unit_entity_id'],
                'ptps_unit_idx',
            );
        });

        $this->registerTable(self::TABLE);
    }

    public function down(): void
    {
        $this->unregisterTable(self::TABLE);
        Schema::dropIfExists(self::TABLE);
    }
};
