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
    private array $tables = ['people_training_effectiveness_reviews'];

    public function up(): void
    {
        Schema::create('people_training_effectiveness_reviews', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->unsignedBigInteger('company_entity_id');
            $table->unsignedBigInteger('training_participant_id');
            $table->string('stage', 16);
            // Deliberately no unique on (participant, stage): the contract says
            // repeating a stage creates another attributable occurrence and
            // must not overwrite the earlier review.
            $table->date('due_on');
            // The governed policy that chose the due date. Required, because
            // the contract refuses to let the code silently anchor on course
            // start, completion or return to work.
            $table->string('due_date_policy', 200);
            $table->date('reviewed_on')->nullable();
            $table->unsignedBigInteger('reviewer_employee_entity_id');

            // Unknown is not zero: an unverified baseline or target stays null.
            $table->unsignedTinyInteger('baseline_level')->nullable();
            $table->unsignedTinyInteger('target_level')->nullable();
            $table->string('requirement_reference', 100)->nullable();
            $table->unsignedInteger('requirement_version')->nullable();

            $table->unsignedTinyInteger('application_rating')->nullable();
            $table->unsignedTinyInteger('improvement_rating')->nullable();
            $table->unsignedTinyInteger('impact_rating')->nullable();
            $table->text('evidence')->nullable();
            $table->string('outcome', 24)->nullable();
            $table->text('further_action')->nullable();
            $table->timestamp('outcome_recorded_at')->nullable();
            $table->unsignedBigInteger('outcome_recorded_by_user_id')->nullable();

            $table->string('state', 24);
            $table->string('closure_route', 24)->nullable();
            $table->text('closure_reason')->nullable();
            // Post level is derived from the linked official reassessment and
            // from nothing else: no manually typed score, no rating average.
            $table->unsignedTinyInteger('post_level')->nullable();
            $table->unsignedBigInteger('reassessment_assessment_id')->nullable();
            $table->string('reassessment_requirement_reference', 100)->nullable();
            $table->unsignedInteger('reassessment_requirement_version')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->unsignedBigInteger('closed_by_user_id')->nullable();
            $table->timestamps();

            $table->unique(['id', 'tenant_id', 'company_entity_id'], 'pter_owner_uq');
            $table->index(['tenant_id', 'company_entity_id', 'training_participant_id', 'stage'], 'pter_register_idx');
            $table->index(['tenant_id', 'company_entity_id', 'state', 'due_on'], 'pter_overdue_idx');
            $table->foreign(['company_entity_id', 'tenant_id'], 'pter_company_fk')
                ->references(['id', 'tenant_id'])->on('companies')->restrictOnDelete();
            $table->foreign(['training_participant_id', 'tenant_id', 'company_entity_id'], 'pter_participant_fk')
                ->references(['id', 'tenant_id', 'company_entity_id'])->on('people_training_participants')
                ->cascadeOnUpdate()->restrictOnDelete();
            // The assessments table carries (id, tenant_id) as its owner key,
            // so the company match is the store's job, not the constraint's.
            $table->foreign(['reassessment_assessment_id', 'tenant_id'], 'pter_reassessment_fk')
                ->references(['id', 'tenant_id'])->on('people_connector_skill_assessments')
                ->cascadeOnUpdate()->restrictOnDelete();
            $table->foreign('reviewer_employee_entity_id', 'pter_reviewer_fk')
                ->references('id')->on('employees')->restrictOnDelete();
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
};
