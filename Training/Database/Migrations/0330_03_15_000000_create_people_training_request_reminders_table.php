<?php

use App\Base\Database\Concerns\IncubatingSchema;
use App\Base\Database\Concerns\RegistersTables;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Approved-but-unlinked training request reminders (blb-people#371 / [0009-h]).
 *
 * One row per request, recipient and ISO week. The week key, not the reminder
 * service, is what makes a rerun harmless: two runs in the same week race for
 * the same unique key and only one wins. Same shape as the evaluation
 * reminders table (0330_03_13).
 */
return new class extends Migration
{
    use IncubatingSchema;
    use RegistersTables;

    private const TABLE = 'people_training_request_reminders';

    public function up(): void
    {
        Schema::create(self::TABLE, function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->unsignedBigInteger('company_entity_id');
            $table->timestamps();
            $table->unique(['id', 'tenant_id', 'company_entity_id'], 'ptrr_owner_uq');
            $table->foreign(['company_entity_id', 'tenant_id'], 'ptrr_company_fk')
                ->references(['id', 'tenant_id'])->on('companies')->restrictOnDelete();

            $table->unsignedBigInteger('training_request_id');
            // ISO year-week, e.g. 2026-W37.
            $table->string('week_key', 8);
            $table->unsignedBigInteger('recipient_user_id');
            $table->timestamp('sent_at');

            $table->unique(['tenant_id', 'training_request_id', 'week_key', 'recipient_user_id'], 'ptrr_week_uq');
            $table->foreign(['training_request_id', 'tenant_id', 'company_entity_id'], 'ptrr_request_fk')
                ->references(['id', 'tenant_id', 'company_entity_id'])->on('people_training_requests')
                ->restrictOnDelete();
        });

        $this->registerTable(self::TABLE);
    }

    public function down(): void
    {
        $this->unregisterTable(self::TABLE);
        Schema::dropIfExists(self::TABLE);
    }
};
