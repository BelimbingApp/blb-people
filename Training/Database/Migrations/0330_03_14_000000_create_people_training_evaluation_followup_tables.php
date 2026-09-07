<?php

use App\Base\Database\Concerns\IncubatingSchema;
use App\Base\Database\Concerns\RegistersTables;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * HR follow-up on evaluation support requests and provider concerns
 * (blb-people#315 / [0012-c]).
 *
 * A follow-up is a separate audited record: opening, progressing or closing
 * one never writes a column on the evaluation row, so the participant's
 * answers stay exactly as submitted. One open follow-up per evaluation per
 * kind is enforced in the store, not the schema — closing then reopening
 * the same kind must stay possible, which a partial unique index would
 * complicate across drivers.
 */
return new class extends Migration
{
    use IncubatingSchema;
    use RegistersTables;

    private array $tables = ['people_training_evaluation_followups', 'people_training_evaluation_followup_audits'];

    public function up(): void
    {
        Schema::create('people_training_evaluation_followups', function (Blueprint $table): void {
            $this->identity($table, 'ptef');
            $table->unsignedBigInteger('evaluation_id');
            $table->string('kind', 24);
            $table->string('status', 24)->default('open');
            $table->text('action_taken')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->unsignedBigInteger('created_by_user_id');
            $table->unsignedBigInteger('updated_by_user_id');
            $this->parent($table, 'evaluation_id', 'people_training_evaluations', 'ptef_evaluation_fk');
        });

        Schema::create('people_training_evaluation_followup_audits', function (Blueprint $table): void {
            $this->identity($table, 'ptefa');
            $table->unsignedBigInteger('training_evaluation_followup_id');
            $table->string('kind', 24);
            $table->string('status', 24);
            $table->text('action_taken')->nullable();
            $table->unsignedBigInteger('actor_user_id');
            $table->timestamp('occurred_at')->nullable();
            $this->parent($table, 'training_evaluation_followup_id', 'people_training_evaluation_followups', 'ptefa_followup_fk');
        });
    }

    /** Same identity and parent shape the evaluation tables use. */
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

    private function parent(Blueprint $table, string $column, string $parent, string $name): void
    {
        $table->foreign([$column, 'tenant_id', 'company_entity_id'], $name)
            ->references(['id', 'tenant_id', 'company_entity_id'])->on($parent)->restrictOnDelete();
    }

    public function down(): void
    {
        Schema::dropIfExists('people_training_evaluation_followup_audits');
        Schema::dropIfExists('people_training_evaluation_followups');
    }
};
