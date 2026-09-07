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
        'people_performance_observations',
        'people_performance_reviews',
        'people_performance_review_observations',
        'people_performance_review_responses',
    ];

    public function up(): void
    {
        Schema::create('people_performance_observations', function (Blueprint $table): void {
            $this->identity($table, 'ppo');
            $table->unsignedBigInteger('employee_entity_id');
            $table->date('window_start');
            $table->date('window_end');
            $table->text('evidence');
            $table->string('source_reference', 160)->nullable();
            $table->unsignedInteger('source_version')->nullable();
            $table->unsignedBigInteger('author_user_id');
            $table->timestamp('recorded_at');
            // A correction supersedes rather than rewrites: this row keeps what
            // it said and points at the row that replaced it.
            $table->unsignedBigInteger('supersedes_observation_id')->nullable();
            $table->text('correction_reason')->nullable();
            $table->timestamp('corrected_at')->nullable();

            $table->index(['tenant_id', 'company_entity_id', 'employee_entity_id', 'window_start'], 'ppo_subject_idx');
            $table->foreign('employee_entity_id', 'ppo_employee_fk')->references('id')->on('employees')->restrictOnDelete();
            $table->foreign(['supersedes_observation_id', 'tenant_id', 'company_entity_id'], 'ppo_supersedes_fk')
                ->references(['id', 'tenant_id', 'company_entity_id'])->on('people_performance_observations')
                ->cascadeOnUpdate()->restrictOnDelete();
        });

        Schema::create('people_performance_reviews', function (Blueprint $table): void {
            $this->identity($table, 'ppr');
            $table->unsignedBigInteger('employee_entity_id');
            $table->uuid('review_key');
            $table->unsignedInteger('version');
            $table->string('status', 16);
            $table->date('period_start');
            $table->date('period_end');
            // The cutoff a historical read reports alongside the version.
            $table->timestamp('cutoff_at');
            $table->string('outcome', 24);
            $table->text('rationale');
            $table->unsignedBigInteger('reviewer_user_id');
            $table->timestamp('finalized_at')->nullable();
            $table->unsignedBigInteger('supersedes_review_id')->nullable();
            $table->text('correction_reason')->nullable();
            $table->timestamp('superseded_at')->nullable();

            $table->unique(['tenant_id', 'company_entity_id', 'review_key', 'version'], 'ppr_version_uq');
            $table->index(['tenant_id', 'company_entity_id', 'employee_entity_id', 'finalized_at'], 'ppr_history_idx');
            $table->foreign('employee_entity_id', 'ppr_employee_fk')->references('id')->on('employees')->restrictOnDelete();
            $table->foreign(['supersedes_review_id', 'tenant_id', 'company_entity_id'], 'ppr_supersedes_fk')
                ->references(['id', 'tenant_id', 'company_entity_id'])->on('people_performance_reviews')
                ->cascadeOnUpdate()->restrictOnDelete();
        });

        Schema::create('people_performance_review_observations', function (Blueprint $table): void {
            $this->identity($table, 'ppro');
            $table->unsignedBigInteger('review_id');
            $table->unsignedBigInteger('observation_id');

            $table->unique(['tenant_id', 'company_entity_id', 'review_id', 'observation_id'], 'ppro_pin_uq');
            $table->foreign(['review_id', 'tenant_id', 'company_entity_id'], 'ppro_review_fk')
                ->references(['id', 'tenant_id', 'company_entity_id'])->on('people_performance_reviews')
                ->cascadeOnUpdate()->restrictOnDelete();
            $table->foreign(['observation_id', 'tenant_id', 'company_entity_id'], 'ppro_observation_fk')
                ->references(['id', 'tenant_id', 'company_entity_id'])->on('people_performance_observations')
                ->cascadeOnUpdate()->restrictOnDelete();
        });

        Schema::create('people_performance_review_responses', function (Blueprint $table): void {
            $this->identity($table, 'pprr');
            $table->unsignedBigInteger('review_id');
            $table->unsignedBigInteger('employee_entity_id');
            $table->text('response');
            $table->timestamp('recorded_at');

            $table->index(['tenant_id', 'company_entity_id', 'review_id'], 'pprr_review_idx');
            $table->foreign(['review_id', 'tenant_id', 'company_entity_id'], 'pprr_review_fk')
                ->references(['id', 'tenant_id', 'company_entity_id'])->on('people_performance_reviews')
                ->cascadeOnUpdate()->restrictOnDelete();
            $table->foreign('employee_entity_id', 'pprr_employee_fk')->references('id')->on('employees')->restrictOnDelete();
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
