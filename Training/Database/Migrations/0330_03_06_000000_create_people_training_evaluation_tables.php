<?php

use App\Base\Database\Concerns\IncubatingSchema;
use App\Base\Database\Concerns\RegistersTables;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Participant training evaluations (blb-people#185 / [0012-b]).
 *
 * Separate tables from participation on purpose: attendance and other confirmed
 * delivery facts stay in the participation record and are not edited through an
 * evaluation. The link is the participant id and nothing more.
 *
 * criteria_version is stored, not pointed at. docs/contracts/training-evaluation.md
 * is explicit that a current-form pointer cannot reproduce an older completed
 * evaluation, and that a later form must not reinterpret retained answers.
 */
return new class extends Migration
{
    use IncubatingSchema;
    use RegistersTables;

    private array $tables = ['people_training_evaluations'];

    public function up(): void
    {
        Schema::create('people_training_evaluations', function (Blueprint $table): void {
            $this->identity($table, 'pte');
            $table->unsignedBigInteger('participant_id');
            $table->unsignedBigInteger('event_id');
            $table->string('employee_subject_id', 160);
            $table->string('criteria_version', 40);

            // Nullable on purpose, and 1-5 where answered. The contract says
            // zero is outside the response scale and is not a replacement for
            // missing input, so an unanswered criterion stays null rather than
            // being scored.
            foreach ([
                'relevance', 'objectives_met', 'content_quality', 'trainer_effectiveness',
                'materials_exercises', 'pace_duration', 'practical_usefulness', 'overall_satisfaction',
            ] as $criterion) {
                $table->unsignedTinyInteger($criterion)->nullable();
            }

            $table->text('most_useful_learning')->nullable();
            $table->text('application_commitment')->nullable();
            $table->text('support_needed')->nullable();
            $table->text('recommendation')->nullable();
            $table->text('issues_or_improvements')->nullable();
            $table->text('notes')->nullable();

            $table->string('status', 24)->default('draft');
            $table->date('due_on')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->unsignedBigInteger('submitted_by_user_id')->nullable();
            $table->string('entry_source', 40)->default('self');

            $table->unique(['tenant_id', 'participant_id'], 'pte_participant_uq');
            $this->parent($table, 'participant_id', 'people_training_participants', 'pte_participant_fk');
        });
    }

    /** Same identity and parent shape the participation tables use. */
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
        Schema::dropIfExists('people_training_evaluations');
    }
};
