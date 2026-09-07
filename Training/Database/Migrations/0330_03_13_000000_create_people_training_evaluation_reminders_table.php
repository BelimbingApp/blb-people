<?php

use App\Base\Database\Concerns\IncubatingSchema;
use App\Base\Database\Concerns\RegistersTables;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Evaluation due reminders (blb-people#323 / [0012-d]).
 *
 * One row per participant per day. The day key, not the reminder service, is
 * what makes a rerun harmless: two runs on the same day race for the same
 * unique key and only one wins.
 */
return new class extends Migration
{
    use IncubatingSchema;
    use RegistersTables;

    private const TABLE = 'people_training_evaluation_reminders';

    public function up(): void
    {
        Schema::create(self::TABLE, function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->unsignedBigInteger('company_entity_id');
            $table->timestamps();
            $table->unique(['id', 'tenant_id', 'company_entity_id'], 'ptevr_owner_uq');
            $table->foreign(['company_entity_id', 'tenant_id'], 'ptevr_company_fk')
                ->references(['id', 'tenant_id'])->on('companies')->restrictOnDelete();

            $table->unsignedBigInteger('event_id');
            $table->unsignedBigInteger('participant_id');
            // Null when the participant had not opened a form when reminded;
            // the due date then came from the event clock.
            $table->unsignedBigInteger('evaluation_id')->nullable();
            $table->date('due_on');
            $table->string('day_key', 10);
            $table->timestamp('notified_at');

            $table->unique(['tenant_id', 'company_entity_id', 'participant_id', 'day_key'], 'ptevr_day_uq');
            $table->foreign(['participant_id', 'tenant_id', 'company_entity_id', 'event_id'], 'ptevr_participant_fk')
                ->references(['id', 'tenant_id', 'company_entity_id', 'event_id'])->on('people_training_participants')
                ->cascadeOnUpdate()->restrictOnDelete();
        });

        $this->registerTable(self::TABLE);
    }

    public function down(): void
    {
        $this->unregisterTable(self::TABLE);
        Schema::dropIfExists(self::TABLE);
    }
};
