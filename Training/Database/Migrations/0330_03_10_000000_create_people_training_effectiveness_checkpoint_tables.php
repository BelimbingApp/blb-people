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

    /** @var list<string> */
    private array $tables = [
        'people_training_effectiveness_answers',
        'people_training_effectiveness_reminders',
    ];

    public function up(): void
    {
        Schema::create('people_training_effectiveness_answers', function (Blueprint $table): void {
            $this->identity($table, 'ptcpa');
            // event_id is carried because the participants table's owner key
            // includes it, and because the checkpoint clock runs from the
            // event's ends_at.
            $table->unsignedBigInteger('event_id');
            $table->unsignedBigInteger('participant_id');
            $table->string('checkpoint', 8);
            $table->unsignedTinyInteger('rating');
            $table->text('comment');
            $table->unsignedBigInteger('answered_by_user_id');
            $table->timestamp('answered_at');

            // One answer per participant per checkpoint. Answering again while
            // the checkpoint is still open revises this row rather than adding
            // a second opinion.
            $table->unique(
                ['tenant_id', 'company_entity_id', 'participant_id', 'checkpoint'],
                'ptcpa_checkpoint_uq',
            );
            $table->foreign(['participant_id', 'tenant_id', 'company_entity_id', 'event_id'], 'ptcpa_participant_fk')
                ->references(['id', 'tenant_id', 'company_entity_id', 'event_id'])->on('people_training_participants')
                ->cascadeOnUpdate()->restrictOnDelete();
        });

        Schema::create('people_training_effectiveness_reminders', function (Blueprint $table): void {
            $this->identity($table, 'ptcpr');
            $table->unsignedBigInteger('event_id');
            $table->unsignedBigInteger('participant_id');
            $table->string('checkpoint', 8);
            $table->unsignedBigInteger('hod_user_id')->nullable();
            $table->timestamp('notified_at');

            $table->unique(
                ['tenant_id', 'company_entity_id', 'participant_id', 'checkpoint'],
                'ptcpr_checkpoint_uq',
            );
            $table->foreign(['participant_id', 'tenant_id', 'company_entity_id', 'event_id'], 'ptcpr_participant_fk')
                ->references(['id', 'tenant_id', 'company_entity_id', 'event_id'])->on('people_training_participants')
                ->cascadeOnUpdate()->restrictOnDelete();
        });

        foreach ($this->tables as $table) {
            $this->registerTable($table);
        }
    }

    public function down(): void
    {
        foreach (array_reverse($this->tables) as $table) {
            $this->unregisterTable($table);
            Schema::dropIfExists($table);
        }
    }

    private function identity(Blueprint $table, string $prefix): void
    {
        $table->id();
        $table->unsignedBigInteger('tenant_id')->index();
        $table->unsignedBigInteger('company_entity_id');
        $table->timestamps();
        $table->unique(['id', 'tenant_id', 'company_entity_id'], $prefix.'_owner_uq');
        $table->foreign(['company_entity_id', 'tenant_id'], $prefix.'_company_fk')
            ->references(['id', 'tenant_id'])->on('companies')->restrictOnDelete();
    }
};
