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

    private array $tables = ['people_training_plans', 'people_training_plan_items'];

    public function up(): void
    {
        Schema::create('people_training_plans', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->unsignedBigInteger('company_entity_id');
            $table->uuid('plan_key');
            $table->unsignedInteger('version');
            $table->unsignedBigInteger('department_entity_id');
            $table->date('period_start');
            $table->date('period_end');
            $table->text('objectives');
            $table->boolean('financial_tracking_enabled')->default(false);
            $table->string('status', 24);
            $table->unsignedBigInteger('accountable_owner_user_id');
            $table->unsignedBigInteger('submitted_by_user_id')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->unsignedBigInteger('approved_by_user_id')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->unsignedBigInteger('amends_plan_id')->nullable();
            $table->text('amendment_reason')->nullable();
            $table->timestamps();

            $table->unique(['id', 'tenant_id', 'company_entity_id'], 'ptplan_owner_uq');
            $table->unique(['tenant_id', 'company_entity_id', 'plan_key', 'version'], 'ptplan_version_uq');
            $table->index(['tenant_id', 'company_entity_id', 'department_entity_id', 'status'], 'ptplan_register_idx');
            $table->foreign(['company_entity_id', 'tenant_id'], 'ptplan_company_fk')
                ->references(['id', 'tenant_id'])->on('companies')->restrictOnDelete();
            $table->foreign('department_entity_id', 'ptplan_department_fk')
                ->references('id')->on('people_reference_entries')->restrictOnDelete();
            $table->foreign(['amends_plan_id', 'tenant_id', 'company_entity_id'], 'ptplan_amends_fk')
                ->references(['id', 'tenant_id', 'company_entity_id'])->on('people_training_plans')->restrictOnDelete();
        });

        Schema::create('people_training_plan_items', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->unsignedBigInteger('company_entity_id');
            $table->unsignedBigInteger('training_plan_id');
            // Stable across amendments: amend() copies an item into the next
            // revision as a new row, so the row id cannot say "this is the
            // same need". The key can, which is what plan-to-event execution
            // keys its once-only rule on.
            $table->uuid('item_key');
            $table->string('need_reference', 160);
            $table->text('expected_result');
            $table->text('target_cohort');
            $table->string('delivery_approach', 24);
            $table->string('responsible_owner_reference', 160);
            $table->string('intended_timing', 160);
            $table->text('evaluation_approach');
            $table->json('budget_line')->nullable();
            $table->timestamps();

            $table->unique(['id', 'tenant_id', 'company_entity_id'], 'ptplan_item_owner_uq');
            $table->unique(['tenant_id', 'company_entity_id', 'training_plan_id', 'item_key'], 'ptplan_item_key_uq');
            $table->index(['tenant_id', 'company_entity_id', 'training_plan_id'], 'ptplan_item_register_idx');
            $table->foreign(['company_entity_id', 'tenant_id'], 'ptplan_item_company_fk')
                ->references(['id', 'tenant_id'])->on('companies')->restrictOnDelete();
            $table->foreign(['training_plan_id', 'tenant_id', 'company_entity_id'], 'ptplan_item_parent_fk')
                ->references(['id', 'tenant_id', 'company_entity_id'])->on('people_training_plans')->restrictOnDelete();
        });

        Schema::table('people_connector_training_events', function (Blueprint $table): void {
            $table->foreign(['plan_id', 'tenant_id', 'company_entity_id'], 'pct_event_plan_fk')
                ->references(['id', 'tenant_id', 'company_entity_id'])->on('people_training_plans')
                ->cascadeOnUpdate()->restrictOnDelete();
            $table->foreign(['plan_item_id', 'tenant_id', 'company_entity_id'], 'pct_event_plan_item_fk')
                ->references(['id', 'tenant_id', 'company_entity_id'])->on('people_training_plan_items')
                ->cascadeOnUpdate()->restrictOnDelete();
        });

        foreach ($this->tables as $table) {
            $this->registerTable($table);
        }
    }

    public function down(): void
    {
        Schema::table('people_connector_training_events', function (Blueprint $table): void {
            $table->dropForeign('pct_event_plan_item_fk');
            $table->dropForeign('pct_event_plan_fk');
        });

        foreach (array_reverse($this->tables) as $table) {
            $this->unregisterTable($table);
            Schema::dropIfExists($table);
        }
    }
};
